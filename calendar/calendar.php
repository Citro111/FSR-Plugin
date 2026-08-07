<?php
/**
 * Calendar integration for FSR ET/IT Website Tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-template.php';
require_once __DIR__ . '/frontend-template.php';

add_action('admin_init', 'fsr_etit_calendar_register_settings');
add_action('admin_enqueue_scripts', 'fsr_etit_calendar_admin_assets');

function fsr_etit_calendar_register_settings(): void {
    register_setting(
        'fsr_etit_calendar_settings',
        FSR_ETIT_OPTION_CALENDAR_URL,
        [
            'type'              => 'string',
            'sanitize_callback' => 'fsr_etit_calendar_sanitize_url',
            'default'           => '',
        ]
    );

    register_setting(
        'fsr_etit_calendar_settings',
        FSR_ETIT_OPTION_CALENDAR_CATEGORIES,
        [
            'type'              => 'array',
            'sanitize_callback' => 'fsr_etit_calendar_sanitize_categories',
            'default'           => [],
        ]
    );
}

function fsr_etit_calendar_normalize_url($url): string {
    $url = esc_url_raw(trim(fsr_etit_scalar_string(wp_unslash($url))), ['https']);
    $parts = wp_parse_url($url);
    if (
        $url === '' ||
        !is_array($parts) ||
        empty($parts['host']) ||
        strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return '';
    }

    return $url;
}

function fsr_etit_calendar_sanitize_url($url): string {
    $raw_url = trim(fsr_etit_scalar_string(wp_unslash($url)));
    if ($raw_url === '') {
        return '';
    }

    $url = fsr_etit_calendar_normalize_url($raw_url);
    if ($url === '') {
        add_settings_error(
            FSR_ETIT_OPTION_CALENDAR_URL,
            'fsr_etit_invalid_calendar_url',
            'Die Kalender-URL ist ungültig. Der bisherige Wert wurde beibehalten.',
            'error'
        );
        return fsr_etit_calendar_normalize_url(
            get_option(FSR_ETIT_OPTION_CALENDAR_URL, '')
        );
    }

    return $url;
}

function fsr_etit_calendar_get_events($url): array {
    $url = fsr_etit_calendar_normalize_url($url);
    if ($url === '') {
        return [];
    }

    $categories = fsr_etit_calendar_sanitize_categories(
        get_option(FSR_ETIT_OPTION_CALENDAR_CATEGORIES, [])
    );
    $category_json = wp_json_encode($categories);
    $events_cache_key = 'fsr_etit_calendar_events_' . md5(
        $url . '|' . ($category_json !== false ? $category_json : '')
    );
    $cached_events = get_transient($events_cache_key);
    if (is_array($cached_events)) {
        return $cached_events;
    }

    $cache_key = 'fsr_etit_calendar_ical_' . md5($url);
    $data = get_transient($cache_key);
    if (!is_string($data)) {
        $response = wp_safe_remote_get($url, [
            'timeout'             => 15,
            'redirection'         => 3,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'user-agent'          => 'FSR-ETIT-Website-Tools/' . FSR_ETIT_VERSION,
        ]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            fsr_etit_log('Kalender konnte nicht geladen werden.');
            return [];
        }

        $data = wp_remote_retrieve_body($response);
        if (!is_string($data) || $data === '') {
            return [];
        }

        set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
    }

    $events = fsr_etit_calendar_parse_ical($data);
    set_transient($events_cache_key, $events, 10 * MINUTE_IN_SECONDS);
    return $events;
}

function fsr_etit_calendar_parse_property(string $event, string $property): array {
    $pattern = '/^' . preg_quote($property, '/') . '((?:;[^:\r\n]*)*):(.*)$/mi';
    if (!preg_match($pattern, $event, $match)) {
        return ['value' => '', 'params' => []];
    }

    $params = [];
    $raw_params = ltrim((string) ($match[1] ?? ''), ';');
    if ($raw_params !== '') {
        foreach (explode(';', $raw_params) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $params[strtoupper(trim($key))] = trim($value, " \t\n\r\0\x0B\"");
        }
    }

    return [
        'value'  => trim((string) ($match[2] ?? '')),
        'params' => $params,
    ];
}

function fsr_etit_calendar_decode_text($value): string {
    $value = str_replace(['\\n', '\\N'], "\n", (string) $value);
    $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
    return trim($value);
}

function fsr_etit_calendar_parse_ical($ical): array {
    $ical = str_replace(["\r\n", "\r"], "\n", (string) $ical);
    $ical = preg_replace("/\n[ \t]/", '', $ical);
    preg_match_all('/BEGIN:VEVENT\s*\n(.*?)\nEND:VEVENT/si', $ical, $matches);

    $events = [];
    foreach (array_slice($matches[1] ?? [], 0, 2000) as $raw) {
        $summary = fsr_etit_calendar_parse_property($raw, 'SUMMARY');
        $start = fsr_etit_calendar_parse_property($raw, 'DTSTART');
        $end = fsr_etit_calendar_parse_property($raw, 'DTEND');
        $location = fsr_etit_calendar_parse_property($raw, 'LOCATION');
        $description = fsr_etit_calendar_parse_property($raw, 'DESCRIPTION');
        $rrule = fsr_etit_calendar_parse_property($raw, 'RRULE');
        $uid = fsr_etit_calendar_parse_property($raw, 'UID');
        $recurrence = fsr_etit_calendar_parse_property($raw, 'RECURRENCE-ID');
        $status = fsr_etit_calendar_parse_property($raw, 'STATUS');

        if ($summary['value'] === '' || $start['value'] === '') {
            continue;
        }

        $title = fsr_etit_calendar_decode_text($summary['value']);
        $start_tzid = $start['params']['TZID'] ?? null;
        $end_tzid = $end['params']['TZID'] ?? $start_tzid;
        $recurrence_tzid = $recurrence['params']['TZID'] ?? $start_tzid;
        $uid_value = sanitize_text_field(fsr_etit_calendar_decode_text($uid['value']));
        $recurrence_id = trim($recurrence['value']);
        if ($recurrence_id === '' && preg_match('/_R(\d{8}T\d{6}Z?)/', $uid_value, $id_match)) {
            $recurrence_id = $id_match[1];
        }

        $timestamp = fsr_etit_calendar_next_timestamp(
            $start['value'],
            $rrule['value'] ?: null,
            $end['value'] ?: null,
            $start_tzid
        );
        $recurrence_timestamp = $recurrence_id !== ''
            ? fsr_etit_calendar_parse_datetime($recurrence_id, $recurrence_tzid)
            : false;
        $event_status = strtoupper(sanitize_key($status['value']));
        $original_start_timestamp = fsr_etit_calendar_parse_datetime($start['value'], $start_tzid);
        $original_end_timestamp = $end['value'] !== ''
            ? fsr_etit_calendar_parse_datetime($end['value'], $end_tzid)
            : false;
        $duration = $original_start_timestamp && $original_end_timestamp
            ? max(0, (int) $original_end_timestamp - (int) $original_start_timestamp)
            : 0;

        if (!$timestamp && $recurrence_timestamp) {
            $timestamp = $original_start_timestamp ?: (int) $recurrence_timestamp;
        }
        if (!$timestamp) {
            continue;
        }

        $type = 'allgemein';
        $clean_title = $title;
        if (preg_match('/^\[(.*?)\]\s*(.*)$/u', $title, $type_match)) {
            $type = sanitize_title($type_match[1]) ?: 'allgemein';
            $clean_title = trim($type_match[2]);
        } else {
            $type = fsr_etit_calendar_detect_category($title);
        }

        $events[] = [
            'title'                => sanitize_text_field($clean_title),
            'type'                 => $type,
            'timestamp'            => $timestamp ?: (int) $recurrence_timestamp,
            'location'             => sanitize_text_field(fsr_etit_calendar_decode_text($location['value'])),
            'description'          => sanitize_textarea_field(fsr_etit_calendar_decode_text($description['value'])),
            'url'                  => fsr_etit_calendar_get_category_url($type),
            'id'                   => $uid_value,
            'recurrence_id'        => $recurrence_id,
            'recurrence_timestamp' => $recurrence_timestamp ?: 0,
            'rrule'                => sanitize_text_field($rrule['value']),
            'status'               => $event_status,
            'end_timestamp'        => $duration > 0 ? (int) ($timestamp ?: $recurrence_timestamp) + $duration : 0,
            'dtstart'              => sanitize_text_field($start['value']),
            'tzid'                 => sanitize_text_field(fsr_etit_scalar_string($start_tzid)),
            'duration'             => $duration,
        ];
    }

    return fsr_etit_calendar_merge_recurrences($events);
}

function fsr_etit_calendar_timezone($tzid): DateTimeZone {
    if (!$tzid) {
        return wp_timezone();
    }

    try {
        return new DateTimeZone((string) $tzid);
    } catch (Throwable $error) {
        return wp_timezone();
    }
}

function fsr_etit_calendar_parse_datetime($value, $tzid = null) {
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $is_utc = str_ends_with($value, 'Z');
    if ($is_utc) {
        $value = substr($value, 0, -1);
    }

    if (preg_match('/^\d{8}$/', $value)) {
        $value .= 'T000000';
    } elseif (preg_match('/^(\d{8})T(\d{2})$/', $value, $match)) {
        $value = $match[1] . 'T' . $match[2] . '0000';
    } elseif (preg_match('/^(\d{8})T(\d{4})$/', $value, $match)) {
        $value = $match[1] . 'T' . $match[2] . '00';
    }

    if (!preg_match('/^\d{8}T\d{6}$/', $value)) {
        return false;
    }

    $timezone = $is_utc ? new DateTimeZone('UTC') : fsr_etit_calendar_timezone($tzid);
    $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $timezone);
    return $date instanceof DateTimeImmutable ? $date->getTimestamp() : false;
}

function fsr_etit_calendar_merge_recurrences($events): array {
    $regular = [];
    $exceptions = [];

    foreach ($events as $event) {
        $uid = preg_replace('/_R\d{8}T\d{6}Z?.*/', '', (string) ($event['id'] ?? ''));
        if ($uid === '') {
            $uid = md5(($event['title'] ?? '') . '|' . ($event['timestamp'] ?? ''));
        }

        if (!empty($event['recurrence_id']) || str_contains((string) ($event['id'] ?? ''), '_R')) {
            $exceptions[$uid][] = $event;
        } else {
            $regular[$uid][] = $event;
        }
    }

    $result = [];
    foreach ($regular as $uid => $masters) {
        foreach ($masters as $master) {
            $replaced = false;
            $guard = 0;
            while ($guard++ < 100) {
                $matching_exception = null;
                foreach ($exceptions[$uid] ?? [] as $exception) {
                    if (fsr_etit_calendar_timestamp_matches_recurrence($master['timestamp'], $exception['recurrence_timestamp'] ?? 0)) {
                        $matching_exception = $exception;
                        break;
                    }
                }

                if (!$matching_exception) {
                    break;
                }

                if (empty($master['rrule'])) {
                    $replaced = true;
                    break;
                }

                $next_timestamp = fsr_etit_calendar_next_timestamp(
                    $master['dtstart'] ?? '',
                    $master['rrule'],
                    null,
                    $master['tzid'] ?? null,
                    (int) $master['timestamp']
                );
                if (!$next_timestamp) {
                    $replaced = true;
                    break;
                }

                $master['timestamp'] = $next_timestamp;
                $master['end_timestamp'] = !empty($master['duration'])
                    ? $next_timestamp + (int) $master['duration']
                    : 0;
            }
            if (!$replaced && ($master['status'] ?? '') !== 'CANCELLED') {
                $result[] = $master;
            }
        }
    }

    foreach ($exceptions as $items) {
        foreach ($items as $exception) {
            if (($exception['status'] ?? '') !== 'CANCELLED' && (int) $exception['timestamp'] >= time()) {
                $result[] = $exception;
            }
        }
    }

    $unique = [];
    foreach ($result as $event) {
        $key = md5(($event['id'] ?? '') . '|' . ($event['timestamp'] ?? '') . '|' . ($event['title'] ?? ''));
        $unique[$key] = $event;
    }

    $result = array_values($unique);
    usort($result, static fn($a, $b): int => (int) $a['timestamp'] <=> (int) $b['timestamp']);
    foreach ($result as &$event) {
        unset($event['dtstart'], $event['tzid'], $event['duration']);
    }
    unset($event);
    return $result;
}

function fsr_etit_calendar_timestamp_matches_recurrence($timestamp, $recurrence_timestamp): bool {
    $timestamp = (int) $timestamp;
    $recurrence_timestamp = (int) $recurrence_timestamp;
    return $timestamp > 0 && $recurrence_timestamp > 0 && abs($timestamp - $recurrence_timestamp) < 60;
}

function fsr_etit_calendar_get_category_url($type): string {
    $categories = fsr_etit_calendar_sanitize_categories(
        get_option(FSR_ETIT_OPTION_CALENDAR_CATEGORIES, [])
    );

    foreach ($categories as $category) {
        $names = array_merge(
            [(string) ($category['name'] ?? '')],
            (array) ($category['additionalNames'] ?? [])
        );
        foreach ($names as $name) {
            if (sanitize_title($name) !== $type) {
                continue;
            }
            return !empty($category['page_id'])
                ? (string) get_permalink(absint($category['page_id']))
                : '';
        }
    }

    return '';
}

function fsr_etit_calendar_sanitize_categories($categories): array {
    if (!is_array($categories)) {
        return [];
    }

    $clean = [];
    foreach (array_slice($categories, 0, 100) as $category) {
        if (!is_array($category)) {
            continue;
        }

        $name = sanitize_text_field(fsr_etit_scalar_string(wp_unslash($category['name'] ?? '')));
        if ($name === '') {
            continue;
        }

        $additional = $category['additionalNames'] ?? [];
        if (is_string($additional)) {
            $additional = explode(',', wp_unslash($additional));
        }
        $additional = is_array($additional) ? $additional : [];
        $additional = array_values(array_unique(array_filter(array_map(
            static fn($item): string => sanitize_text_field(
                fsr_etit_scalar_string(wp_unslash($item))
            ),
            $additional
        ))));

        $clean[] = [
            'name'            => $name,
            'additionalNames' => $additional,
            'page_id'         => absint(fsr_etit_scalar_string($category['page_id'] ?? 0)),
        ];
    }

    return $clean;
}

function fsr_etit_calendar_detect_category($title): string {
    $categories = fsr_etit_calendar_sanitize_categories(
        get_option(FSR_ETIT_OPTION_CALENDAR_CATEGORIES, [])
    );
    $search = fsr_etit_lowercase($title);

    foreach ($categories as $category) {
        $names = array_merge(
            [(string) ($category['name'] ?? '')],
            (array) ($category['additionalNames'] ?? [])
        );
        foreach ($names as $name) {
            $name = trim(fsr_etit_lowercase($name));
            if ($name !== '' && str_contains($search, $name)) {
                return sanitize_title($category['name']) ?: 'allgemein';
            }
        }
    }

    return 'allgemein';
}

function fsr_etit_calendar_admin_assets($hook): void {
    if (!str_contains((string) $hook, 'fsr-etit-settings-calendar')) {
        return;
    }

    wp_enqueue_script('jquery');
    if (wp_script_is('select2', 'registered')) {
        wp_enqueue_script('select2');
    }
    if (wp_style_is('select2', 'registered')) {
        wp_enqueue_style('select2');
    }
}

function fsr_etit_calendar_advance_date(
    DateTimeImmutable $date,
    string $frequency,
    int $interval,
    int $anchor_month,
    int $anchor_day
) {
    switch ($frequency) {
        case 'DAILY':
            return $date->modify('+' . $interval . ' days');
        case 'WEEKLY':
            return $date->modify('+' . $interval . ' weeks');
        case 'MONTHLY':
            $candidate = $date->modify('first day of this month')->modify('+' . $interval . ' months');
            for ($guard = 0; $guard < 1200; $guard++) {
                $year = (int) $candidate->format('Y');
                $month = (int) $candidate->format('n');
                if (checkdate($month, $anchor_day, $year)) {
                    return $candidate->setDate($year, $month, $anchor_day);
                }
                $candidate = $candidate->modify('+' . $interval . ' months');
            }
            return false;
        case 'YEARLY':
            $year = (int) $date->format('Y') + $interval;
            for ($guard = 0; $guard < 500; $guard++, $year += $interval) {
                if (checkdate($anchor_month, $anchor_day, $year)) {
                    return $date->setDate($year, $anchor_month, $anchor_day);
                }
            }
            return false;
        default:
            return false;
    }
}

function fsr_etit_calendar_next_timestamp(
    $date_string,
    $recurrence_rule = null,
    $end_date_string = null,
    $tzid = null,
    $after_timestamp = null
) {
    static $recurrence_work = 0;
    $threshold = max(
        time(),
        $after_timestamp === null ? 0 : absint(fsr_etit_scalar_string($after_timestamp)) + 1
    );
    $start_timestamp = fsr_etit_calendar_parse_datetime($date_string, $tzid);
    if (!$start_timestamp) {
        return false;
    }
    if (!$recurrence_rule) {
        return $start_timestamp >= $threshold ? $start_timestamp : false;
    }

    $rules = [];
    foreach (explode(';', strtoupper((string) $recurrence_rule)) as $part) {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
            $rules[trim($key)] = trim($value);
        }
    }

    $until = !empty($rules['UNTIL'])
        ? fsr_etit_calendar_parse_datetime($rules['UNTIL'], $tzid)
        : false;
    if ($until && $until < $threshold) {
        return false;
    }

    $frequency = $rules['FREQ'] ?? '';
    $interval = max(1, min(100, (int) ($rules['INTERVAL'] ?? 1)));
    $count = isset($rules['COUNT']) ? max(1, (int) $rules['COUNT']) : null;
    $timezone = fsr_etit_calendar_timezone($tzid);
    $current = (int) $start_timestamp;
    $start_date = (new DateTimeImmutable('@' . $current))->setTimezone($timezone);
    $anchor_month = (int) $start_date->format('n');
    $anchor_day = (int) $start_date->format('j');
    $iterations = 0;

    while ($current < $threshold && $iterations < 5000) {
        if (++$recurrence_work > 200000) {
            return false;
        }
        if ($count !== null && $iterations >= $count - 1) {
            return false;
        }

        $date = (new DateTimeImmutable('@' . $current))->setTimezone($timezone);
        $date = fsr_etit_calendar_advance_date(
            $date,
            $frequency,
            $interval,
            $anchor_month,
            $anchor_day
        );
        if (!$date) {
            return false;
        }

        $current = $date->getTimestamp();
        $iterations++;
    }

    if ($iterations >= 5000 || ($until && $current > $until)) {
        return false;
    }

    return $current >= $threshold ? $current : false;
}

function fsr_etit_calendar_search($search_query): array {
    $calendar_url = get_option(FSR_ETIT_OPTION_CALENDAR_URL, '');
    if (!$calendar_url) {
        return [];
    }

    $search = trim(fsr_etit_lowercase(wp_strip_all_tags($search_query)));
    if ($search === '') {
        return [];
    }

    $results = [];
    foreach (fsr_etit_calendar_get_events($calendar_url) as $event) {
        $haystack = fsr_etit_lowercase(implode(' ', [
            $event['title'] ?? '',
            $event['description'] ?? '',
            $event['location'] ?? '',
            $event['type'] ?? '',
        ]));
        if (!str_contains($haystack, $search)) {
            continue;
        }

        $results[] = fsr_etit_create_virtual_post(
            $event['title'],
            $event['description'],
            $event['description'],
            $event['url'] ?: home_url('/'),
            (int) $event['timestamp'],
            'page'
        );
    }

    return $results;
}
