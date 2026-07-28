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
    error_log('CALENDAR: Parsing iCal data' . print_r($events, true));
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $matches);
    error_log('CALENDAR: Found ' . count($matches[1]) . ' events in calendar data.');
    error_log('===================Parsing Events========================');
    foreach ($matches[1] as $raw) {
        error_log('CALENDAR: Parsing event data: ' . print_r($raw, true));
        preg_match('/SUMMARY:(.*)/', $raw, $title);
        preg_match('/DTSTART(?:;[^:]*)?:(.*)/', $raw, $start_date);
        preg_match('/DTEND(?:;[^:]*)?:(.*)/', $raw, $end_date);
        preg_match('/LOCATION:(.*)/', $raw, $location);
        preg_match('/DESCRIPTION:(.*)/', $raw, $description);
        preg_match('/RRULE:(.*)/', $raw, $recurrence);
        preg_match('/UID:(.*)/', $raw, $uid_match);
        preg_match('/RECURRENCE-ID(?:;[^:]*)?:(.*)/', $raw, $recurrence_id_match);
        $uid = trim($uid_match[1] ?? '');
        $recurrence_id = trim($recurrence_id_match[1] ?? '');
        if ($recurrence_id === '' && preg_match('/_R(\d{8}T\d{6})/', $uid, $m)) {
            $recurrence_id = $m[1];
        }
        if (empty($title[1]) || empty($start_date[1])) {
            continue;
        }
        $date_string = trim($start_date[1]);
        $end_date_string = !empty($end_date[1]) ? trim($end_date[1]) : null;
        $rrule = $recurrence[1] ?? null;
        $timestamp = fsr_get_next_event_timestamp($date_string, $rrule, $end_date_string);
        if (!$timestamp) {
            continue;
        }
        $raw_title = trim($title[1]);
        $type = 'allgemein';
        $clean_title = $raw_title;
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $raw_title, $matches)) {
            $type = sanitize_title($matches[1]);
            $clean_title = trim($matches[2]);
        } else {
            $type = fsr_detect_event_category($raw_title);
        }
        error_log('CALENDAR: Event "' . $clean_title . '" detected as type "' . $type . '" with timestamp ' . $timestamp);
        error_log('CALENDAR: Date string: ' . $date_string . ', Recurrence: ' . ($rrule ?? 'none') . ', End Date: ' . ($end_date_string ?? 'none'));
        error_log("UID: " . ($uid ?? 'none'));
        error_log("RECURRENCE-ID: " . ($recurrence_id ?? 'none'));
        error_log("====================Event Details========================");
        error_log(print_r($raw, true));
        error_log("=======================End========================");
        error_log("");
        $events[] = [
            'title' => $clean_title,
            'type' => $type,
            'timestamp' => $timestamp,
            'location' => isset($location[1]) ? trim($location[1]) : '',
            'description' => isset($description[1]) ? trim($description[1]) : '',
            'url' => fsr_get_category_url($type),
            'id' => $uid,
            'recurrence_id' => $recurrence_id,
        ];
    }
    error_log('CALENDAR: Parsed ' . count($events) . ' events from calendar data.');
    return $events;
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
        $category['name'] = sanitize_text_field(
            $category['name']
        );
        $category['page_id'] = absint(
            $category['page_id'] ?? 0
        );
        if(isset($category['additionalNames'])) {
            $category['additionalNames'] =
                array_map(
                    'sanitize_text_field',
                    explode(
                        ',',
                        $category['additionalNames']
                    )
                );
        }
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

function fsr_get_next_event_timestamp($date_string, $recurrence_rule = null, $end_date_string = null) {
    $now = current_time('timestamp');
    $start_timestamp = strtotime($date_string);
    if (!$start_timestamp) {
        error_log('CALENDAR: Invalid start date string: ' . $date_string);
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
        $until_timestamp = strtotime($rules['UNTIL']);
        if ($until_timestamp && $until_timestamp < $now) {
            return false;
        }
    }
    $current = $start_timestamp;
    $freq = $rules['FREQ'] ?? '';
    $interval = isset($rules['INTERVAL']) ? max(1, (int)$rules['INTERVAL']) : 1;
    $iterations = 0;
    $max_iterations = 1000;
    while ($current < $now && $iterations < $max_iterations) {
        switch ($freq) {
            case 'DAILY':
                $current = strtotime("+{$interval} day", $current);
                break;
            case 'WEEKLY':
                $current = strtotime('+' . (7 * $interval) . ' days', $current);
                break;
            case 'MONTHLY':
                $current = strtotime("+{$interval} month", $current);
                break;
            case 'YEARLY':
                $current = strtotime("+{$interval} year", $current);
                break;
            default:
                error_log('CALENDAR: Unsupported recurrence frequency: ' . $freq);
                return false;
        }
        $iterations++;
    }
    error_log('CALENDAR: Next occurrence timestamp: ' . $current . ' (now: ' . $now . ')');
    return $current >= $now ? $current : false;
}