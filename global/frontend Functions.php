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