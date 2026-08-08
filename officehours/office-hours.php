<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/templates/office-hours-frontend.php';
require_once __DIR__ . '/templates/office-hours-portal.php';

add_action('admin_init', 'fsr_etit_office_hours_register_settings');
add_action('template_redirect', 'fsr_etit_office_hours_process_portal_request', 5);
add_shortcode('fsr_office_hours_portal', 'fsr_etit_office_hours_portal_shortcode');
add_shortcode('fsr_office_hours', 'fsr_etit_office_hours_shortcode');

function fsr_etit_office_hours_register_settings(): void {
    register_setting(
        'fsr_etit_office_hours_settings',
        FSR_ETIT_OPTION_OFFICE_HOURS,
        [
            'type'              => 'array',
            'sanitize_callback' => 'fsr_etit_sanitize_office_hours_settings',
            'default'           => ['rules' => [], 'cancellations' => []],
        ]
    );
}

/**
 * Capability required for every write in the frontend management portal.
 */
function fsr_etit_office_hours_manage_capability(): string {
    $capability = sanitize_key(fsr_etit_scalar_string(
        apply_filters('fsr_etit_office_hours_manage_capability', 'manage_options')
    ));
    return $capability !== '' ? $capability : 'manage_options';
}

function fsr_etit_office_hours_get_settings(): array {
    $settings = get_option(FSR_ETIT_OPTION_OFFICE_HOURS, []);
    $settings = is_array($settings) ? $settings : [];
    return fsr_etit_sanitize_office_hours_settings($settings);
}

function fsr_etit_office_hours_sanitize_time($time, string $fallback = '10:00'): string {
    $time = trim(fsr_etit_scalar_string($time));
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $fallback;
}

function fsr_etit_office_hours_is_valid_date($date): bool {
    $date = fsr_etit_scalar_string($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function fsr_etit_office_hours_sanitize_date($date): string {
    $date = sanitize_text_field(fsr_etit_scalar_string($date));
    return fsr_etit_office_hours_is_valid_date($date)
        ? $date
        : wp_date('Y-m-d', time(), wp_timezone());
}

function fsr_etit_office_hours_normalize_member_ids($incoming_ids): array {
    $incoming_ids = is_array($incoming_ids) ? $incoming_ids : [$incoming_ids];
    $incoming_ids = array_filter($incoming_ids, 'is_scalar');
    $ids = array_map('absint', $incoming_ids);
    return array_values(array_unique(array_filter($ids)));
}

function fsr_etit_office_hours_new_rule_id(): string {
    return 'rule_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
}

function fsr_etit_office_hours_sanitize_rule($rule, int $index = 0): array {
    $rule = is_array($rule) ? $rule : [];
    $id = sanitize_key(fsr_etit_scalar_string($rule['id'] ?? '')) ?: fsr_etit_office_hours_new_rule_id();
    $recurrence = sanitize_key(fsr_etit_scalar_string($rule['recurrence'] ?? 'weekly'));
    $recurrence = in_array($recurrence, ['monthly_nth', 'weekly'], true) ? $recurrence : 'weekly';
    $start_time = fsr_etit_office_hours_sanitize_time($rule['start_time'] ?? '10:00', '10:00');
    $end_time = fsr_etit_office_hours_sanitize_time($rule['end_time'] ?? '12:00', '12:00');
    if ($end_time <= $start_time) {
        $start_time = '10:00';
        $end_time = '12:00';
    }

    return [
        'id'            => $id,
        'type'          => sanitize_key(fsr_etit_scalar_string($rule['type'] ?? 'office_hour')) ?: 'office_hour',
        'title'         => sanitize_text_field(fsr_etit_scalar_string($rule['title'] ?? 'Sprechstunde')) ?: 'Sprechstunde',
        'recurrence'    => $recurrence,
        'nth_week'      => max(1, min(4, absint(fsr_etit_scalar_string($rule['nth_week'] ?? 1)))),
        'weekday'       => max(1, min(7, absint(fsr_etit_scalar_string($rule['weekday'] ?? 3)))),
        'week_interval' => max(1, min(8, absint(fsr_etit_scalar_string($rule['week_interval'] ?? 1)))),
        'start_time'    => $start_time,
        'end_time'      => $end_time,
        'location'      => sanitize_text_field(fsr_etit_scalar_string($rule['location'] ?? 'FSR-Büro')) ?: 'FSR-Büro',
        'member_ids'    => fsr_etit_office_hours_normalize_member_ids($rule['member_ids'] ?? []),
        'created_at'    => sanitize_text_field(fsr_etit_scalar_string($rule['created_at'] ?? current_time('mysql'))),
        'notes'         => sanitize_text_field(fsr_etit_scalar_string($rule['notes'] ?? '')),
        'start_date'    => fsr_etit_office_hours_sanitize_date($rule['start_date'] ?? current_time('Y-m-d')),
    ];
}

function fsr_etit_sanitize_office_hours_settings($input): array {
    $input = is_array($input) ? $input : [];
    $clean = ['rules' => [], 'cancellations' => []];
    $rule_ids = [];
    $rule_members = [];
    $rules_by_id = [];

    foreach (array_slice((array) ($input['rules'] ?? []), 0, 500) as $index => $rule) {
        $rule = fsr_etit_office_hours_sanitize_rule($rule, $index);
        if (empty($rule['member_ids'])) {
            continue;
        }
        if (isset($rule_ids[$rule['id']])) {
            $rule['id'] = fsr_etit_office_hours_new_rule_id();
        }
        $rule_ids[$rule['id']] = true;
        $rule_members[$rule['id']] = $rule['member_ids'];
        $rules_by_id[$rule['id']] = $rule;
        $clean['rules'][] = $rule;
    }

    $seen = [];
    foreach (array_slice((array) ($input['cancellations'] ?? []), 0, 5000) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rule_id = sanitize_key(fsr_etit_scalar_string($item['rule_id'] ?? ''));
        $member_id = absint(fsr_etit_scalar_string($item['member_id'] ?? 0));
        $date = sanitize_text_field(fsr_etit_scalar_string($item['occurrence_date'] ?? ''));
        if (
            $rule_id === '' ||
            $member_id === 0 ||
            !fsr_etit_office_hours_is_valid_date($date) ||
            !isset($rule_members[$rule_id]) ||
            !in_array($member_id, $rule_members[$rule_id], true) ||
            !fsr_etit_office_hours_date_matches_rule($rules_by_id[$rule_id] ?? [], $date)
        ) {
            continue;
        }

        $key = $rule_id . '|' . $member_id . '|' . $date;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean['cancellations'][] = [
            'rule_id'        => $rule_id,
            'member_id'      => $member_id,
            'occurrence_date'=> $date,
            'reason'         => sanitize_text_field(fsr_etit_scalar_string($item['reason'] ?? '')),
            'created_at'     => sanitize_text_field(fsr_etit_scalar_string($item['created_at'] ?? current_time('mysql'))),
        ];
    }

    return $clean;
}

function fsr_etit_office_hours_save_settings(array $settings): bool {
    $clean = fsr_etit_sanitize_office_hours_settings($settings);
    if (update_option(FSR_ETIT_OPTION_OFFICE_HOURS, $clean, false)) {
        return true;
    }

    return get_option(FSR_ETIT_OPTION_OFFICE_HOURS, null) === $clean;
}

function fsr_etit_office_hours_get_all_members(): array {
    $data = fsr_etit_get_members_data('all');
    return is_array($data['members'] ?? null) ? $data['members'] : [];
}

function fsr_etit_office_hours_is_allowed_member(array $member): bool {
    return in_array(
        $member['team'] ?? '',
        [FSR_ETIT_TEAM_ELECTED, FSR_ETIT_TEAM_HELPERS],
        true
    );
}

function fsr_etit_office_hours_get_allowed_members(): array {
    $allowed = [];
    foreach (fsr_etit_office_hours_get_all_members() as $member) {
        if (!is_array($member) || empty($member['id']) || !fsr_etit_office_hours_is_allowed_member($member)) {
            continue;
        }
        $name = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
        $allowed[] = [
            'id'   => (int) $member['id'],
            'name' => $name !== '' ? $name : ('ID ' . (int) $member['id']),
        ];
    }
    return $allowed;
}

function fsr_etit_office_hours_allowed_member_ids(): array {
    return array_map(
        static fn(array $member): int => (int) $member['id'],
        fsr_etit_office_hours_get_allowed_members()
    );
}

function fsr_etit_office_hours_get_members_by_id(): array {
    $map = [];
    foreach (fsr_etit_office_hours_get_all_members() as $member) {
        if (is_array($member) && !empty($member['id'])) {
            $map[(int) $member['id']] = $member;
        }
    }
    return $map;
}

function fsr_etit_office_hours_get_rule_members(array $rule): array {
    $member_map = fsr_etit_office_hours_get_members_by_id();
    $names = [];
    foreach ((array) ($rule['member_ids'] ?? []) as $member_id) {
        $member = $member_map[(int) $member_id] ?? null;
        if (!$member || !fsr_etit_office_hours_is_allowed_member($member)) {
            continue;
        }
        $name = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

function fsr_etit_office_hours_member_param(): int {
    $value = $_GET['member'] ?? $_POST['member'] ?? 0;
    return absint(fsr_etit_scalar_string(wp_unslash($value)));
}

function fsr_etit_office_hours_get_member_by_id(int $member_id): ?array {
    $member = fsr_etit_office_hours_get_members_by_id()[$member_id] ?? null;
    return is_array($member) ? $member : null;
}

function fsr_etit_office_hours_get_selected_member(): ?array {
    static $resolved = false;
    static $selected = null;
    if ($resolved) {
        return $selected;
    }
    $resolved = true;

    $member = fsr_etit_office_hours_get_member_by_id(fsr_etit_office_hours_member_param());
    if ($member && fsr_etit_office_hours_is_allowed_member($member)) {
        return $selected = $member;
    }

    $allowed = fsr_etit_office_hours_get_allowed_members();
    return $selected = !empty($allowed)
        ? fsr_etit_office_hours_get_member_by_id((int) $allowed[0]['id'])
        : null;
}

function fsr_etit_office_hours_update_cancellation(
    string $rule_id,
    int $member_id,
    string $date,
    string $reason,
    bool $cancel
): bool {
    $settings = fsr_etit_office_hours_get_settings();
    $filtered = [];
    foreach ($settings['cancellations'] as $entry) {
        $matches =
            ($entry['rule_id'] ?? '') === $rule_id &&
            (int) ($entry['member_id'] ?? 0) === $member_id &&
            ($entry['occurrence_date'] ?? '') === $date;
        if (!$matches) {
            $filtered[] = $entry;
        }
    }

    if ($cancel) {
        $filtered[] = [
            'rule_id'         => $rule_id,
            'member_id'       => $member_id,
            'occurrence_date' => $date,
            'reason'          => sanitize_text_field($reason),
            'created_at'      => current_time('mysql'),
        ];
    }

    $settings['cancellations'] = $filtered;
    return fsr_etit_office_hours_save_settings($settings);
}

function fsr_etit_office_hours_member_is_cancelled(
    array $cancellations,
    string $rule_id,
    string $date,
    int $member_id
): bool {
    foreach ($cancellations as $entry) {
        if (
            ($entry['rule_id'] ?? '') === $rule_id &&
            (int) ($entry['member_id'] ?? 0) === $member_id &&
            ($entry['occurrence_date'] ?? '') === $date
        ) {
            return true;
        }
    }
    return false;
}

function fsr_etit_office_hours_occurrence_is_cancelled($rule, $date, $cancellations): bool {
    $member_ids = fsr_etit_office_hours_normalize_member_ids($rule['member_ids'] ?? []);
    if (empty($member_ids)) {
        return true;
    }
    foreach ($member_ids as $member_id) {
        if (!fsr_etit_office_hours_member_is_cancelled($cancellations, $rule['id'], $date, $member_id)) {
            return false;
        }
    }
    return true;
}

function fsr_etit_office_hours_date_object(string $date): ?DateTimeImmutable {
    if (!fsr_etit_office_hours_is_valid_date($date)) {
        return null;
    }
    $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
    return $value instanceof DateTimeImmutable ? $value : null;
}

function fsr_etit_office_hours_nth_weekday_date($year, $month, $weekday, $nth): ?string {
    $first = fsr_etit_office_hours_date_object(sprintf('%04d-%02d-01', $year, $month));
    if (!$first) {
        return null;
    }
    $delta = ((int) $weekday - (int) $first->format('N') + 7) % 7;
    $candidate = $first->modify('+' . ($delta + ((max(1, (int) $nth) - 1) * 7)) . ' days');
    return (int) $candidate->format('n') === (int) $month ? $candidate->format('Y-m-d') : null;
}

function fsr_etit_office_hours_collect_occurrences(
    array $rules,
    int $limit = 12,
    bool $hide_fully_cancelled = true,
    ?array $cancellations = null,
    ?array $allowed_member_ids = null
): array {
    $limit = max(1, min(200, $limit));
    if ($cancellations === null) {
        $cancellations = fsr_etit_office_hours_get_settings()['cancellations'];
    }
    if ($allowed_member_ids === null) {
        $allowed_member_ids = fsr_etit_office_hours_allowed_member_ids();
    }
    $allowed_member_ids = fsr_etit_office_hours_normalize_member_ids($allowed_member_ids);
    $today = new DateTimeImmutable('today', wp_timezone());
    $bucket = [];

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $rule = fsr_etit_office_hours_sanitize_rule($rule);
        $rule['member_ids'] = array_values(array_intersect(
            $rule['member_ids'],
            $allowed_member_ids
        ));
        if (empty($rule['member_ids'])) {
            continue;
        }
        $start_date = fsr_etit_office_hours_date_object($rule['start_date']) ?: $today;

        if ($rule['recurrence'] === 'monthly_nth') {
            for ($offset = 0; $offset < 24; $offset++) {
                $month = $today->modify('first day of +' . $offset . ' months');
                $date = fsr_etit_office_hours_nth_weekday_date(
                    (int) $month->format('Y'),
                    (int) $month->format('n'),
                    $rule['weekday'],
                    $rule['nth_week']
                );
                $candidate = $date ? fsr_etit_office_hours_date_object($date) : null;
                if (!$candidate || $candidate < $today || $candidate < $start_date) {
                    continue;
                }
                fsr_etit_office_hours_add_occurrence(
                    $bucket,
                    $rule,
                    $date,
                    $cancellations,
                    $hide_fully_cancelled
                );
            }
            continue;
        }

        $delta = ($rule['weekday'] - (int) $start_date->format('N') + 7) % 7;
        $candidate = $start_date->modify('+' . $delta . ' days');
        $interval = max(1, (int) $rule['week_interval']);
        $guard = 0;
        while ($candidate < $today && $guard < 5200) {
            $candidate = $candidate->modify('+' . $interval . ' weeks');
            $guard++;
        }
        for ($index = 0; $index < max(52, $limit * 2); $index++) {
            $date = $candidate->format('Y-m-d');
            fsr_etit_office_hours_add_occurrence(
                $bucket,
                $rule,
                $date,
                $cancellations,
                $hide_fully_cancelled
            );
            $candidate = $candidate->modify('+' . $interval . ' weeks');
        }
    }

    usort($bucket, static function ($left, $right): int {
        return strcmp(
            $left['date'] . ' ' . $left['start_time'],
            $right['date'] . ' ' . $right['start_time']
        );
    });
    return array_slice(array_values($bucket), 0, $limit);
}

function fsr_etit_office_hours_add_occurrence(
    array &$bucket,
    array $rule,
    string $date,
    array $cancellations,
    bool $hide_fully_cancelled
): void {
    if (
        $hide_fully_cancelled &&
        fsr_etit_office_hours_occurrence_is_cancelled($rule, $date, $cancellations)
    ) {
        return;
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

function fsr_etit_office_hours_find_rule_index(array $rules, string $rule_id): ?int {
    foreach ($rules as $index => $rule) {
        if (sanitize_key((string) ($rule['id'] ?? '')) === $rule_id) {
            return (int) $index;
        }
    }
    return null;
}

function fsr_etit_office_hours_date_matches_rule(array $rule, string $date): bool {
    $candidate = fsr_etit_office_hours_date_object($date);
    $rule = fsr_etit_office_hours_sanitize_rule($rule);
    $start = fsr_etit_office_hours_date_object($rule['start_date']);
    if (!$candidate || !$start || $candidate < $start || (int) $candidate->format('N') !== $rule['weekday']) {
        return false;
    }

    if ($rule['recurrence'] === 'monthly_nth') {
        return $date === fsr_etit_office_hours_nth_weekday_date(
            (int) $candidate->format('Y'),
            (int) $candidate->format('n'),
            $rule['weekday'],
            $rule['nth_week']
        );
    }

    $delta = ($rule['weekday'] - (int) $start->format('N') + 7) % 7;
    $first = $start->modify('+' . $delta . ' days');
    if ($candidate < $first) {
        return false;
    }

    $days = (int) $first->diff($candidate)->format('%a');
    return $days % (7 * max(1, (int) $rule['week_interval'])) === 0;
}

function fsr_etit_office_hours_is_portal_submission(): bool {
    foreach ([
        'fsr_oh_join_submit',
        'fsr_oh_cancellation_submit',
        'fsr_oh_create_rule_submit',
        'fsr_oh_delete_rule_submit',
        'fsr_oh_leave_rule_submit',
        'fsr_oh_edit_rule_submit',
    ] as $action) {
        if (isset($_POST[$action])) {
            return true;
        }
    }
    return false;
}

/**
 * Returns the current portal request URI as a local redirect target.
 * Keeping this relative avoids losing the portal page when no HTTP referrer is
 * available (for example because of browser privacy settings).
 */
function fsr_etit_office_hours_current_request_path(): string {
    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? wp_sanitize_redirect(fsr_etit_scalar_string(wp_unslash($_SERVER['REQUEST_URI'])))
        : '';

    if ($request_uri === '' || $request_uri[0] !== '/' || str_starts_with($request_uri, '//')) {
        return '/';
    }

    return $request_uri;
}

function fsr_etit_office_hours_sanitize_return_path($value): string {
    $path = wp_sanitize_redirect(fsr_etit_scalar_string($value));
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return '';
    }
    return $path;
}

/**
 * Processes portal writes before the theme sends output and prevents duplicate
 * form submissions when the result page is refreshed.
 */
function fsr_etit_office_hours_process_portal_request(): void {
    $method = isset($_SERVER['REQUEST_METHOD'])
        ? strtoupper(sanitize_text_field(fsr_etit_scalar_string(wp_unslash($_SERVER['REQUEST_METHOD']))))
        : 'GET';
    if ($method !== 'POST' || !fsr_etit_office_hours_is_portal_submission()) {
        return;
    }

    [$success, $message] = fsr_etit_office_hours_handle_portal_actions();
    set_transient(
        'fsr_etit_office_hours_notice_' . get_current_user_id(),
        ['success' => (bool) $success, 'message' => sanitize_text_field($message)],
        MINUTE_IN_SECONDS
    );

    $redirect = isset($_POST['_fsr_oh_return_path'])
        ? fsr_etit_office_hours_sanitize_return_path(wp_unslash($_POST['_fsr_oh_return_path']))
        : '';
    if ($redirect === '') {
        $referer = wp_get_referer();
        $redirect = $referer ? $referer : fsr_etit_office_hours_current_request_path();
    }

    $member_id = isset($_POST['member'])
        ? absint(fsr_etit_scalar_string(wp_unslash($_POST['member'])))
        : 0;
    if ($member_id > 0) {
        $redirect = remove_query_arg('member', $redirect);
        $redirect = add_query_arg('member', $member_id, $redirect);
    }

    // The fallback is the current portal request itself, never the homepage.
    $fallback = fsr_etit_office_hours_current_request_path();
    $redirect = wp_validate_redirect($redirect, $fallback);
    wp_safe_redirect($redirect, 303, 'FSR ET/IT Website Tools');
    exit;
}

function fsr_etit_office_hours_get_notice(): array {
    $key = 'fsr_etit_office_hours_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);
    return is_array($notice)
        ? [(bool) ($notice['success'] ?? false), sanitize_text_field(fsr_etit_scalar_string($notice['message'] ?? ''))]
        : [false, ''];
}

function fsr_etit_office_hours_handle_portal_actions(): array {
    $request_method = isset($_SERVER['REQUEST_METHOD'])
        ? strtoupper(sanitize_text_field(fsr_etit_scalar_string(wp_unslash($_SERVER['REQUEST_METHOD']))))
        : 'GET';
    if ($request_method !== 'POST') {
        return [false, ''];
    }
    if (!current_user_can(fsr_etit_office_hours_manage_capability())) {
        return [false, 'Du hast keine Berechtigung, Sprechstunden zu ändern.'];
    }

    $post = wp_unslash($_POST);
    $settings = fsr_etit_office_hours_get_settings();
    $rules = $settings['rules'];
    $allowed_member_ids = fsr_etit_office_hours_allowed_member_ids();

    if (isset($post['fsr_oh_join_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_join_nonce'] ?? '')), 'fsr_oh_join_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $member_id = absint(fsr_etit_scalar_string($post['member'] ?? 0));
        $rule_id = sanitize_key(fsr_etit_scalar_string($post['rule_id'] ?? ''));
        $index = fsr_etit_office_hours_find_rule_index($rules, $rule_id);
        if (!in_array($member_id, $allowed_member_ids, true) || $index === null) {
            return [false, 'Mitglied oder Sprechstunde wurde nicht gefunden.'];
        }
        $rules[$index]['member_ids'] = array_values(array_unique(array_merge(
            fsr_etit_office_hours_normalize_member_ids($rules[$index]['member_ids'] ?? []),
            [$member_id]
        )));
        $settings['rules'] = $rules;
        if (!fsr_etit_office_hours_save_settings($settings)) {
            return [false, 'Die Änderung konnte nicht gespeichert werden.'];
        }
        return [true, 'Das Mitglied wurde zur Sprechstunde hinzugefügt.'];
    }

    if (isset($post['fsr_oh_cancellation_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_cancel_nonce'] ?? '')), 'fsr_oh_cancellation_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $member_id = absint(fsr_etit_scalar_string($post['member'] ?? 0));
        $rule_id = sanitize_key(fsr_etit_scalar_string($post['rule_id'] ?? ''));
        $date = sanitize_text_field(fsr_etit_scalar_string($post['date'] ?? ''));
        $index = fsr_etit_office_hours_find_rule_index($rules, $rule_id);
        if (
            $index === null ||
            !fsr_etit_office_hours_is_valid_date($date) ||
            !fsr_etit_office_hours_date_matches_rule($rules[$index] ?? [], $date) ||
            !in_array($member_id, fsr_etit_office_hours_normalize_member_ids($rules[$index]['member_ids'] ?? []), true)
        ) {
            return [false, 'Ungültiger Termin.'];
        }
        $cancel = sanitize_key(fsr_etit_scalar_string($post['cancel_action'] ?? 'cancel')) !== 'uncancel';
        $saved = fsr_etit_office_hours_update_cancellation(
            $rule_id,
            $member_id,
            $date,
            sanitize_text_field(fsr_etit_scalar_string($post['reason'] ?? '')),
            $cancel
        );
        if (!$saved) {
            return [false, 'Die Änderung konnte nicht gespeichert werden.'];
        }
        return [true, $cancel ? 'Teilnahme wurde abgesagt.' : 'Teilnahme wurde wieder aktiviert.'];
    }

    if (isset($post['fsr_oh_create_rule_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_create_nonce'] ?? '')), 'fsr_oh_create_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $member_id = absint(fsr_etit_scalar_string($post['member'] ?? 0));
        if (!in_array($member_id, $allowed_member_ids, true)) {
            return [false, 'Mitglied nicht gefunden oder nicht erlaubt.'];
        }
        $start_time = fsr_etit_office_hours_sanitize_time($post['start_time'] ?? '', '10:00');
        $end_time = fsr_etit_office_hours_sanitize_time($post['end_time'] ?? '', '12:00');
        if ($end_time <= $start_time) {
            return [false, 'Die Endzeit muss nach der Startzeit liegen.'];
        }
        $additional = array_intersect(
            fsr_etit_office_hours_normalize_member_ids($post['member_ids'] ?? []),
            $allowed_member_ids
        );
        $rule = fsr_etit_office_hours_sanitize_rule([
            'id'            => fsr_etit_office_hours_new_rule_id(),
            'type'          => $post['type'] ?? 'office_hour',
            'title'         => $post['title'] ?? 'Sprechstunde',
            'recurrence'    => 'weekly',
            'weekday'       => $post['weekday'] ?? 3,
            'nth_week'      => $post['nth_week'] ?? 1,
            'week_interval' => $post['week_interval'] ?? 1,
            'start_time'    => $start_time,
            'end_time'      => $end_time,
            'location'      => $post['location'] ?? 'FSR-Büro',
            'member_ids'    => array_merge([$member_id], $additional),
            'created_at'    => current_time('mysql'),
            'notes'         => $post['notes'] ?? '',
            'start_date'    => $post['start_date'] ?? current_time('Y-m-d'),
        ]);
        $settings['rules'][] = $rule;
        if (!fsr_etit_office_hours_save_settings($settings)) {
            return [false, 'Die Sprechstunde konnte nicht gespeichert werden.'];
        }
        return [true, 'Neue Sprechstunde wurde gespeichert.'];
    }

    if (isset($post['fsr_oh_delete_rule_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_delete_nonce'] ?? '')), 'fsr_oh_delete_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $rule_id = sanitize_key(fsr_etit_scalar_string($post['rule_id'] ?? ''));
        $index = fsr_etit_office_hours_find_rule_index($rules, $rule_id);
        if ($index === null) {
            return [false, 'Sprechstunde wurde nicht gefunden.'];
        }
        unset($rules[$index]);
        $settings['rules'] = array_values($rules);
        $settings['cancellations'] = array_values(array_filter(
            $settings['cancellations'],
            static fn($entry): bool => ($entry['rule_id'] ?? '') !== $rule_id
        ));
        if (!fsr_etit_office_hours_save_settings($settings)) {
            return [false, 'Die Sprechstunde konnte nicht gelöscht werden.'];
        }
        return [true, 'Sprechstunde wurde gelöscht.'];
    }

    if (isset($post['fsr_oh_leave_rule_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_leave_nonce'] ?? '')), 'fsr_oh_leave_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $member_id = absint(fsr_etit_scalar_string($post['member'] ?? 0));
        $rule_id = sanitize_key(fsr_etit_scalar_string($post['rule_id'] ?? ''));
        $index = fsr_etit_office_hours_find_rule_index($rules, $rule_id);
        if ($index === null) {
            return [false, 'Sprechstunde wurde nicht gefunden.'];
        }

        $member_ids = fsr_etit_office_hours_normalize_member_ids($rules[$index]['member_ids'] ?? []);
        if (!in_array($member_id, $member_ids, true)) {
            return [false, 'Das Mitglied nimmt nicht an dieser Sprechstunde teil.'];
        }
        if (count($member_ids) <= 1) {
            return [false, 'Die Sprechstunde hat keine weiteren Teilnehmenden und kann daher nur gelöscht werden.'];
        }

        $rules[$index]['member_ids'] = array_values(array_filter(
            $member_ids,
            static fn(int $id): bool => $id !== $member_id
        ));
        $settings['rules'] = array_values($rules);
        $settings['cancellations'] = array_values(array_filter(
            $settings['cancellations'],
            static fn($entry): bool => !(
                ($entry['rule_id'] ?? '') === $rule_id &&
                (int) ($entry['member_id'] ?? 0) === $member_id
            )
        ));
        if (!fsr_etit_office_hours_save_settings($settings)) {
            return [false, 'Die Sprechstunde konnte nicht verlassen werden.'];
        }
        return [true, 'Sprechstunde wurde verlassen.'];
    }

    if (isset($post['fsr_oh_edit_rule_submit'])) {
        if (!wp_verify_nonce(sanitize_text_field(fsr_etit_scalar_string($post['_fsr_oh_edit_nonce'] ?? '')), 'fsr_oh_edit_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $rule_id = sanitize_key(fsr_etit_scalar_string($post['rule_id'] ?? ''));
        $index = fsr_etit_office_hours_find_rule_index($rules, $rule_id);
        if ($index === null) {
            return [false, 'Sprechstunde wurde nicht gefunden.'];
        }
        $start_time = fsr_etit_office_hours_sanitize_time($post['start_time'] ?? '', $rules[$index]['start_time']);
        $end_time = fsr_etit_office_hours_sanitize_time($post['end_time'] ?? '', $rules[$index]['end_time']);
        if ($end_time <= $start_time) {
            return [false, 'Die Endzeit muss nach der Startzeit liegen.'];
        }
        $rules[$index] = fsr_etit_office_hours_sanitize_rule(array_merge($rules[$index], [
            'title'         => $post['title'] ?? $rules[$index]['title'],
            'location'      => $post['location'] ?? $rules[$index]['location'],
            'type'          => $post['type'] ?? $rules[$index]['type'],
            'recurrence'    => 'weekly',
            'weekday'       => $post['weekday'] ?? $rules[$index]['weekday'],
            'nth_week'      => $post['nth_week'] ?? $rules[$index]['nth_week'],
            'week_interval' => $post['week_interval'] ?? $rules[$index]['week_interval'],
            'start_time'    => $start_time,
            'end_time'      => $end_time,
            'notes'         => $post['notes'] ?? $rules[$index]['notes'],
            'start_date'    => $post['start_date'] ?? $rules[$index]['start_date'],
        ]));
        $settings['rules'] = array_values($rules);
        if (!fsr_etit_office_hours_save_settings($settings)) {
            return [false, 'Die Sprechstunde konnte nicht gespeichert werden.'];
        }
        return [true, 'Sprechstunde wurde aktualisiert.'];
    }

    return [false, 'Unbekannte Aktion.'];
}

function fsr_etit_office_hours_describe_rule(array $rule): string {
    $labels = [
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
        5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag',
    ];
    $weekday = $labels[(int) ($rule['weekday'] ?? 3)] ?? 'Mittwoch';
    if (($rule['recurrence'] ?? '') === 'weekly') {
        $interval = max(1, (int) ($rule['week_interval'] ?? 1));
        return $interval === 1 ? 'Jeden ' . $weekday : 'Alle ' . $interval . ' Wochen, ' . $weekday;
    }
    return max(1, (int) ($rule['nth_week'] ?? 1)) . '. ' . $weekday . ' im Monat';
}

function fsr_etit_office_hours_search(string $search): array {
    $search = trim(fsr_etit_lowercase(wp_strip_all_tags($search)));
    if ($search === '') {
        return [];
    }

    $settings = fsr_etit_office_hours_get_settings();
    $member_map = fsr_etit_office_hours_get_members_by_id();
    $allowed_member_ids = fsr_etit_office_hours_allowed_member_ids();
    $usage = fsr_etit_get_shortcode_usage('fsr_office_hours');
    $base_url = $usage['fsr_office_hours'][0]['view'] ?? home_url('/');
    $results = [];

    foreach ($settings['rules'] as $rule) {
        $rule = fsr_etit_office_hours_sanitize_rule($rule);
        $member_names = [];
        foreach ($rule['member_ids'] as $member_id) {
            $member = $member_map[$member_id] ?? null;
            if ($member && in_array($member_id, $allowed_member_ids, true)) {
                $member_names[] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            }
        }
        $occurrences = fsr_etit_office_hours_collect_occurrences(
            [$rule],
            52,
            true,
            $settings['cancellations'],
            $allowed_member_ids
        );
        if (empty($occurrences)) {
            continue;
        }
        $matching_occurrence = null;
        foreach ($occurrences as $occurrence) {
            $date_text = wp_date(
                'd.m.Y',
                fsr_etit_office_hours_date_object($occurrence['date'])->getTimestamp(),
                wp_timezone()
            );
            if (str_contains(fsr_etit_lowercase($date_text . ' ' . $occurrence['date']), $search)) {
                $matching_occurrence = $occurrence;
                break;
            }
        }
        $haystack = fsr_etit_lowercase(implode(' ', [
            $rule['title'], $rule['location'], $rule['type'], $rule['notes'],
            implode(' ', $member_names), fsr_etit_office_hours_describe_rule($rule),
        ]));
        if (!str_contains($haystack, $search) && !$matching_occurrence) {
            continue;
        }

        $occurrence = $matching_occurrence ?: ($occurrences[0] ?? null);
        $content = implode("\n", [
            $rule['title'],
            'Mitglieder: ' . implode(', ', $member_names),
            fsr_etit_office_hours_rule_to_text($rule),
        ]);
        $results[] = fsr_etit_create_virtual_post(
            $rule['title'],
            $content,
            $content,
            add_query_arg(
                [
                    'fsr_oh_rule' => $rule['id'],
                    'fsr_oh_date' => $occurrence['date'] ?? '',
                ],
                $base_url
            ),
            $occurrence['date'] ?? '',
            'page'
        );
    }

    return $results;
}

function fsr_etit_office_hours_rule_to_text(array $rule): string {
    return sprintf(
        '%s, %s–%s Uhr, Ort: %s',
        fsr_etit_office_hours_describe_rule($rule),
        $rule['start_time'],
        $rule['end_time'],
        $rule['location']
    );
}
