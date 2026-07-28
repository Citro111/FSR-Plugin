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

function fsr_parse_ical($ical){
    $events = [];
    preg_match_all(
        '/BEGIN:VEVENT(.*?)END:VEVENT/s',
        $ical,
        $matches
    );
    error_log('CALENDAR: Found ' . count($matches[1]) . ' events in calendar data.');
    foreach($matches[1] as $raw){
        preg_match(
            '/SUMMARY:(.*)/',
            $raw,
            $title
        );
        preg_match(
            '/DTSTART[^:]*:(.*)/',
            $raw,
            $date
        );
        preg_match(
            '/LOCATION:(.*)/',
            $raw,
            $location
        );
        preg_match(
            '/DESCRIPTION:(.*)/',
            $raw,
            $description
        );
        if(
            empty($title[1]) ||
            empty($date[1])
        ){
            continue;
        }
        $date_string = trim($date[1]);
        $timestamp = strtotime(
            $date_string
        );
        error_log(
            "DTSTART: {$date_string} -> "
            . gmdate('Y-m-d H:i:s', $timestamp)
            . " UTC / "
            . date('Y-m-d H:i:s', $timestamp)
            . " Local"
        );
        error_log("RAW DTSTART: " . $date_string);
        error_log("Timestamp: " . $timestamp);
        error_log("Title: " . $title[1]);
        error_log("");
        $raw_title = trim($title[1]);
        $type = 'none';
        $clean_title = $raw_title;
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $raw_title, $matches)) {
            $type = sanitize_title($matches[1]);
            $clean_title = trim($matches[2]);
        } else {
            $type = fsr_detect_event_category($raw_title);
        }

        $events[] = [
            'title'=>$clean_title,
            'type'=>$type,
            'timestamp'=>$timestamp,
            'location'=>isset($location[1])
                ? trim($location[1])
                : '',
            'description'=>isset($description[1])
                ? trim($description[1])
                : '',
            'url' => fsr_get_category_url($type)
        ];
        // error_log('CALENDAR: Event added: ' . print_r($events[count($events)-1], true));
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
        if(
            sanitize_title($category['name']) === $type
        ) {
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
        FSR_CALENDAR_URL
    );
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