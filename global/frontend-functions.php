<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates an in-memory post for the combined website search.
 */
function fsr_etit_create_virtual_post(
    $title,
    $excerpt,
    $content,
    $url,
    $date,
    $type = 'page'
) {
    $title = sanitize_text_field(fsr_etit_scalar_string($title));
    $excerpt = sanitize_textarea_field(fsr_etit_scalar_string($excerpt));
    $content = wp_kses_post(fsr_etit_scalar_string($content));
    $content = $content !== '' ? $content : esc_html($excerpt);
    $type = fsr_etit_scalar_string($type);
    $type = post_type_exists($type) ? $type : 'page';

    $url = esc_url_raw(fsr_etit_scalar_string($url), ['http', 'https']);
    if ($url === '') {
        $url = home_url('/');
    }

    if ($date instanceof DateTimeInterface) {
        $date = $date->format('Y-m-d H:i:s');
    } elseif (is_int($date) || (is_string($date) && ctype_digit($date))) {
        $date = wp_date('Y-m-d H:i:s', (int) $date, wp_timezone());
    } else {
        $date = sanitize_text_field(fsr_etit_scalar_string($date));
        if ($date === '' || strtotime($date) === false) {
            $date = current_time('mysql');
        }
    }

    $id = fsr_etit_next_virtual_post_id();
    if (!isset($GLOBALS['fsr_etit_virtual_posts'])) {
        $GLOBALS['fsr_etit_virtual_posts'] = [];
    }

    $GLOBALS['fsr_etit_virtual_posts'][$id] = [
        'url'        => $url,
        'type'       => $type,
        'date'       => $date,
        'source'     => '',
        'location'   => '',
        'event_type' => '',
    ];

    return new WP_Post((object) [
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
        'post_date'          => $date,
        'post_date_gmt'      => get_gmt_from_date($date),
        'post_modified'      => $date,
        'post_modified_gmt'  => get_gmt_from_date($date),
        'menu_order'         => 0,
        'comment_status'     => 'closed',
        'ping_status'        => 'closed',
        'filter'             => 'raw',
    ]);
}

/**
 * Unicode-aware lowercase helper with a safe fallback.
 */
function fsr_etit_lowercase($value): string {
    $value = fsr_etit_scalar_string($value);
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

/**
 * Converts request/settings values without triggering array-to-string notices.
 */
function fsr_etit_scalar_string($value): string {
    return is_scalar($value) ? (string) $value : '';
}

/**
 * Optional debug logging. Disabled by default in the updater settings.
 */
function fsr_etit_log($message): void {
    $logging = false;
    if (function_exists('fsr_etit_updates_settings')) {
        $logging = !empty(fsr_etit_updates_settings()['logging']);
    }

    if (!$logging) {
        return;
    }

    if (is_array($message) || is_object($message)) {
        $encoded = wp_json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message = $encoded !== false ? $encoded : '[nicht serialisierbar]';
    }

    $message = trim((string) $message);
    $message = preg_replace(
        '/\b(?:nonce|_wpnonce)\b\s*[:=]\s*[^\s,}]+/i',
        'nonce=[redacted]',
        $message
    );
    $message = substr($message, 0, 2000);

    $log = get_transient('fsr_etit_debug_log');
    $log = is_array($log) ? $log : [];
    $log[] = '[' . current_time('mysql') . '] ' . $message;
    $log = array_slice($log, -100);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FSR ET/IT: ' . $message);
    }

    set_transient('fsr_etit_debug_log', $log, 5 * MINUTE_IN_SECONDS);
}

/**
 * Forwards buffered messages to Query Monitor when it is available.
 */
function fsr_etit_flush_log(): void {
    $log = get_transient('fsr_etit_debug_log');
    if (!is_array($log)) {
        return;
    }

    foreach ($log as $line) {
        do_action('qm/debug', $line);
    }
}

add_action('load-toplevel_page_fsr-etit-settings', 'fsr_etit_flush_log');
