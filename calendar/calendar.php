<?php
/**
 * FSR Website Tools
 * Calendar integration
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/admin-template.php';
require_once __DIR__ . '/frontend-template.php';

add_action('admin_init', function () {
    register_setting(
        'fsr_settings',
        FSR_CALENDAR_URL
    );
});

function fsr_get_calendar_events($url){
    $response = wp_remote_get(
        $url,
        [
            'timeout'=>15
        ]
    );
    if(
        is_wp_error($response)
    ){
        return [];
    }
    $data = wp_remote_retrieve_body($response);
    if(!$data){
        return [];
    }
    return fsr_parse_ical($data);
}

function fsr_parse_ical($ical) {
    $events = [];
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $matches);
    fsr_updates_log('CALENDAR: Found ' . count($matches[1]) . ' events in calendar data.');
    fsr_updates_log('===================Parsing Events========================');
    foreach ($matches[1] as $raw) {
        preg_match('/SUMMARY:(.*)/', $raw, $title_match);
        preg_match('/DTSTART(?:;TZID=([^:;]+))?(?:;[^:]*)?:(.*)/', $raw, $start_match);
        preg_match('/DTEND(?:;TZID=([^:;]+))?(?:;[^:]*)?:(.*)/', $raw, $end_match);
        preg_match('/LOCATION:(.*)/', $raw, $location_match);
        preg_match('/DESCRIPTION:(.*)/', $raw, $description_match);
        preg_match('/RRULE:(.*)/', $raw, $rrule_match);
        preg_match('/UID:(.*)/', $raw, $uid_match);
        preg_match('/RECURRENCE-ID(?:;TZID=([^:;]+))?(?:;[^:]*)?:(.*)/', $raw, $recurrence_match);
        if (empty($title_match[1]) || empty($start_match[2])) {
            continue;
        }
        $title = trim($title_match[1]);
        $start_tzid  = $start_match[1] ?? null;
        $start_value = trim($start_match[2]);
        $end_tzid  = $end_match[1] ?? null;
        $end_value = !empty($end_match[2]) ? trim($end_match[2]) : null;
        $rrule = !empty($rrule_match[1]) ? trim($rrule_match[1]) : null;
        $uid = trim($uid_match[1] ?? '');
        $recurrence_id = trim($recurrence_match[2] ?? '');
        // Falls Google die Instanz nur in der UID kodiert
        if ($recurrence_id === '' && preg_match('/_R(\d{8}T\d{6})/', $uid, $m)) {
            $recurrence_id = $m[1];
            fsr_updates_log('CALENDAR: Extracted recurrence ID from UID: ' . $recurrence_id);
        }
        $timestamp = fsr_get_next_event_timestamp(
            $start_value,
            $rrule,
            $end_value,
            $start_tzid
        );
        if (!$timestamp) {
            continue;
        }
        $type = 'allgemein';
        $clean_title = $title;
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $title, $m)) {
            $type = sanitize_title($m[1]);
            $clean_title = trim($m[2]);
        } else {
            $type = fsr_detect_event_category($title);
        }
        fsr_updates_log('CALENDAR: Event "' . $clean_title . '" detected as type "' . $type . '" with timestamp ' . $timestamp);
        fsr_updates_log('CALENDAR: Date string: ' . $start_value . ', Recurrence: ' . ($rrule ?? 'none') . ', End Date: ' . ($end_value ?? 'none'));
        fsr_updates_log('UID: ' . ($uid ?: 'none'));
        fsr_updates_log('RECURRENCE-ID: ' . ($recurrence_id ?: 'none'));
        $events[] = [
            'title'         => $clean_title,
            'type'          => $type,
            'timestamp'     => $timestamp,
            'location'      => !empty($location_match[1]) ? trim($location_match[1]) : '',
            'description'   => !empty($description_match[1]) ? trim($description_match[1]) : '',
            'url'           => fsr_get_category_url($type),
            'id'            => $uid,
            'recurrence_id' => $recurrence_id,
            'rrule'         => $rrule,
        ];
    }
    fsr_updates_log('CALENDAR: Parsed ' . count($events) . ' events from calendar data.');
    return fsr_merge_calendar_recurrences($events);
}

function fsr_parse_ical_datetime($value, $tzid = null) {
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
    }
    if (preg_match('/^(\d{8})T(\d{2})$/', $value, $m)) {
        $value = $m[1] . 'T' . $m[2] . '0000';
    }
    if (preg_match('/^(\d{8})T(\d{2})(\d{2})$/', $value, $m)) {
        $value = $m[1] . 'T' . $m[2] . $m[3] . '00';
    }
    $timezone = $is_utc
        ? new DateTimeZone('UTC')
        : ($tzid ? new DateTimeZone($tzid) : wp_timezone());
    $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $timezone);
    return $dt ? $dt->getTimestamp() : false;
}

function fsr_normalize_ical_datetime_string($value) {
    $value = trim($value);
    if (preg_match('/^\d{8}$/', $value)) {
        return $value . 'T000000';
    }
    if (preg_match('/^(\d{8})T(\d{2})$/', $value, $m)) {
        return $m[1] . 'T' . $m[2] . '0000';
    }
    if (preg_match('/^(\d{8})T(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . 'T' . $m[2] . $m[3] . '00';
    }
    if (preg_match('/^(\d{8})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . 'T' . $m[2] . $m[3] . $m[4];
    }
    return $value;
}

function fsr_merge_calendar_recurrences($events) {
    $masters = [];
    $exceptions = [];
    foreach ($events as $event) {
        $base_uid = preg_replace(
            '/_R\d{8}T\d{6}.*/',
            '',
            $event['id']
        );
        $is_exception =
            !empty($event['recurrence_id']) ||
            str_contains($event['id'], '_R');
        if ($is_exception) {
            $exceptions[$base_uid][] = $event;
            fsr_updates_log(
                'CALENDAR: Found exception for UID '
                . $base_uid
                . ' -> '
                . $event['title']
            );
        } else {
            $masters[$base_uid] = $event;
            fsr_updates_log(
                'CALENDAR: Found master UID '
                . $base_uid
                . ' -> '
                . $event['title']
            );
        }
    }
    $result = [];
    foreach ($masters as $uid => $master) {
        if (!empty($exceptions[$uid])) {
            foreach ($exceptions[$uid] as $exception) {
                fsr_updates_log(
                    'CALENDAR: Replacing master '
                    . $master['title']
                    . ' with exception '
                    . $exception['title']
                );
                $result[] = $exception;
            }
        } else {
            $result[] = $master;
        }
    }
    // Falls Google nur Exceptions exportiert
    foreach ($exceptions as $uid => $items) {
        if (!isset($masters[$uid])) {
            foreach ($items as $item) {
                $result[] = $item;
            }
        }
    }
    fsr_updates_log(
        'CALENDAR: After recurrence merge: '
        . count($result)
        . ' events'
    );
    return $result;
}

function fsr_timestamp_matches_recurrence($timestamp, $recurrence_id) {
    if (!$recurrence_id) {
        return false;
    }
    $recurrence_timestamp = strtotime($recurrence_id);
    if (!$recurrence_timestamp) {
        return false;
    }
    return abs(
        $timestamp - $recurrence_timestamp
    ) < 86400;
}

function fsr_get_category_url($type) {
    $categories = get_option(
        'fsr_calendar_categories',
        []
    );
    foreach($categories as $category) {
        if(sanitize_title($category['name']) === $type) {
            return !empty($category['page_id'])
                ? get_permalink($category['page_id'])
                : '';
        }
        foreach(
            ($category['additionalNames'] ?? []) 
            as $name
        ) {
            if(
                sanitize_title($name) === $type
            ) {
                return !empty($category['page_id'])
                    ? get_permalink($category['page_id'])
                    : '';
            }
        }
    }
    return '';
}

add_action('admin_init', function () {
    register_setting(
        'fsr_settings',
        'fsr_calendar_categories',
        [
            'sanitize_callback' => 'fsr_sanitize_categories'
        ]
    );
});
function fsr_sanitize_categories($categories) {
    if (!is_array($categories)) {
        return [];
    }
    foreach ($categories as &$category) {
        $category['name'] = sanitize_text_field($category['name'] ?? '');
        $category['page_id'] = absint($category['page_id'] ?? 0);
        $names = $category['additionalNames'] ?? [];
        if (!is_array($names)) {
            $names = explode(',', $names);
        }
        $category['additionalNames'] = array_values(
            array_filter(
                array_map(
                    'sanitize_text_field',
                    array_map('trim', $names)
                )
            )
        );
    }
    return $categories;
}

function fsr_detect_event_category($title) {
    $categories = get_option(
        'fsr_calendar_categories',
        []
    );
    $search = strtolower($title);
    foreach ($categories as $category) {
        $names = [];
        $names[] = $category['name'];
        if (!empty($category['additionalNames'])) {
            if (is_array($category['additionalNames'])) {
                $names = array_merge(
                    $names,
                    $category['additionalNames']
                );
            } else {
                $names = array_merge(
                    $names,
                    explode(
                        ',',
                        $category['additionalNames']
                    )
                );
            }
        }
        foreach ($names as $name) {
            $name = trim(strtolower($name));
            if ($name !== '' && str_contains($search, $name)) {
                return sanitize_title(
                    $category['name']
                );
            }
        }
    }
    return 'allgemein';
}

function fsr_calendar_admin_scripts($hook) {
    if ($hook !== 'settings_page_fsr-settings') {
        return;
    }
    wp_enqueue_style('select2');
    wp_enqueue_script('select2');
}
add_action(
    'admin_enqueue_scripts',
    'fsr_calendar_admin_scripts'
);

add_action(
    'wp_ajax_fsr_search_pages',
    'fsr_search_pages'
);
function fsr_search_pages() {
    $term = sanitize_text_field(
        $_GET['q'] ?? ''
    );
    $pages = get_posts([
        'post_type' => 'page',
        'posts_per_page' => 20,
        's' => $term
    ]);
    $results = [];
    foreach ($pages as $page) {
        $results[] = [
            'id' => $page->ID,
            'text' => $page->post_title
        ];
    }
    wp_send_json([
        'results' => $results
    ]);
}

function fsr_get_next_event_timestamp($date_string, $recurrence_rule = null, $end_date_string = null, $tzid = null) {
    $now = current_time('timestamp');
    $start_timestamp = fsr_parse_ical_datetime($date_string, $tzid);
    if (!$start_timestamp) {
        fsr_updates_log('CALENDAR: Invalid start date string: ' . $date_string);
        return false;
    }
    if (!$recurrence_rule) {
        return $start_timestamp >= $now ? $start_timestamp : false;
    }
    $rules = [];
    foreach (explode(';', $recurrence_rule) as $part) {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
            $rules[$key] = $value;
        }
    }
    if (!empty($rules['UNTIL'])) {
        $until_timestamp = fsr_parse_ical_datetime($rules['UNTIL'], $tzid);
        if ($until_timestamp && $until_timestamp < $now) {
            return false;
        }
    }
    $current = $start_timestamp;
    $freq = $rules['FREQ'] ?? '';
    $interval = isset($rules['INTERVAL']) ? max(1, (int) $rules['INTERVAL']) : 1;
    $iterations = 0;
    $max_iterations = 1000;
    while ($current < $now && $iterations < $max_iterations) {
        $dt = (new DateTimeImmutable('@' . $current))->setTimezone($tzid ? new DateTimeZone($tzid) : wp_timezone());
        switch ($freq) {
            case 'DAILY':
                $dt = $dt->modify("+{$interval} day");
                break;
            case 'WEEKLY':
                $dt = $dt->modify('+' . (7 * $interval) . ' days');
                break;
            case 'MONTHLY':
                $dt = $dt->modify("+{$interval} month");
                break;
            case 'YEARLY':
                $dt = $dt->modify("+{$interval} year");
                break;
            default:
                fsr_updates_log('CALENDAR: Unsupported recurrence frequency: ' . $freq);
                return false;
        }
        $current = $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $iterations++;
    }
    fsr_updates_log('CALENDAR: Next occurrence timestamp: ' . $current . ' (now: ' . $now . ')');
    return $current >= $now ? $current : false;
}

function fsr_calendar_search($search_query) {
    $calendar_url = get_option(FSR_CALENDAR_URL);
    if (!$calendar_url) {
        return [];
    }
    $events = fsr_get_calendar_events($calendar_url);
    $search = strtolower(trim($search_query));
    $results = [];
    foreach ($events as $event) {
        if (str_contains(strtolower($event['title']), $search)) {
            $results[] = fsr_create_virtual_post(
                $event['title'],
                $event['description'],
                $event['description'],
                $event['url'],
                strtotime($event['timestamp']),
                'page'
            );
        }
    }
    return $results;
}