<?php
if(!defined('ABSPATH')) exit;
function fsr_create_virtual_post(
    $title,
    $excerpt,
    $content,
    $url,
    $date,
    $type = 'page'
) {

    if ($content === '') {
        $content = $excerpt;
    }

    $type = post_type_exists($type) ? $type : 'page';

    $id = fsr_next_virtual_post_id();
    if (!isset($GLOBALS['fsr_virtual_posts'])) {
        $GLOBALS['fsr_virtual_posts'] = [];
    }

    $GLOBALS['fsr_virtual_posts'][$id] = [
        'url'  => $url,
        'type' => $type,
        'date' => $date,
        'source' => '',
        'location' => '',
        'event_type' => '',
    ];
    
    return new WP_Post((object)[
        'ID'                 => $id,
        'post_title'         => $title,
        'post_excerpt'       => $excerpt,
        'post_content'       => $content,
        'post_status'        => 'publish',
        'post_type'          => $type,
        'post_name'          => sanitize_title($title),
        'guid'               => $url,
        'url'                => $url,
        'post_author'        => 0,
        'post_date'          => $date ?: current_time('mysql'),
        'post_date_gmt'      => $date ? get_gmt_from_date($date) : current_time('mysql', true),
        'post_modified'      => $date ?: current_time('mysql'),
        'post_modified_gmt'  => $date ? get_gmt_from_date($date) : current_time('mysql', true),
        'menu_order'         => 0,
        'comment_status'     => 'closed',
        'ping_status'        => 'closed',
        'filter'             => 'raw',
    ]);
}

function fsr_updates_log($message) {
    static $logging;
    $logging ??= fsr_updates_settings()['logging'];
    if (!$logging) return;
    $log = get_transient('fsr_updates_qm_log');
    if (!is_array($log)) {
        $log = [];
    }

    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    } else {
        $message = (string) $message;
    }

    $log[] = '[' . current_time('mysql') . '] ' . $message;
    error_log('FSR UPDATES LOG: ' . $message);

    set_transient('fsr_updates_qm_log', $log, 1 * MINUTE_IN_SECONDS);
}

function fsr_updates_flush_log() {
    $log = get_transient('fsr_updates_qm_log');

    if (empty($log) || !is_array($log)) {
        return;
    }

    foreach ($log as $line) {
        do_action('qm/debug', $line);
    }
}
add_action(
    'load-admin_page_fsr-etit-settings-updates',
    'fsr_updates_flush_log'
);