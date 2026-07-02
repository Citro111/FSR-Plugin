<?php
if(!defined('ABSPATH')) exit;


add_filter('the_posts', 'fsr_ensure_search_loop_runs', 10, 2);
add_action('loop_end', 'fsr_append_search_results');
add_filter('post_class', 'fsr_mark_placeholder_post', 10, 3);
add_filter('post_link', 'fsr_virtual_permalink', 10, 2);

function fsr_ensure_search_loop_runs($posts, $query) {
    if (is_admin() || !$query->is_main_query() || !$query->is_search()) return $posts;
    if (!empty($posts)) return $posts;

    $dummy = new WP_Post((object) [
        'ID' => -1,
        'post_author' => 0,
        'post_date' => current_time('mysql'),
        'post_date_gmt' => current_time('mysql', 1),
        'post_content' => '',
        'post_title' => '',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'post_name' => 'search-placeholder',
        'to_ping' => '',
        'pinged' => '',
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1),
        'post_content_filtered'=> '',
        'post_parent' => 0,
        'guid' => '',
        'menu_order' => 0,
        'post_type' => 'post',
        'post_mime_type' => '',
        'comment_count' => 0,
        'filter' => 'raw',
    ]);

    return [$dummy];
}

function fsr_append_search_results($query) {

    static $done = false;

    if ($done || is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return;
    }

    $search = trim(get_search_query(false));
    $virtual_posts = [
        'membercards' => fsr_membercards_search($search),
        'officehours' => fsr_office_hours_search($search),
        'dokuwiki' => fsr_dw_search($search)
    ];

    if ($search === '') {
        return;
    }

    $done = true;
}

function fsr_mark_placeholder_post($classes, $class, $post_id) {
    if ($post_id === -1) {
        $classes[] = 'search-placeholder';
    }
    return $classes;
}

function fsr_virtual_permalink($permalink, $post) {
    if ($post->ID === -1) {
        return home_url('/?fsr_search_placeholder=1');
    }
    return $permalink;
}

function fsr_create_virtual_search_post($id = -1, $title = '', $content = '') {
    $post = new WP_Post((object)[
        'ID' => $id,
        'post_title' => $title,
        'post_excerpt' => '',
        'post_content' => $content,
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_author' => 0,
        'post_date' => current_time('mysql'),
        'post_date_gmt' => current_time('mysql', 1),
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1),
        'post_parent' => 0,
        'menu_order' => 0,
        'post_mime_type' => '',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'to_ping' => '',
        'pinged' => '',
        'guid' => '',
        'comment_count' => 0,
        'filter' => 'raw',

    ]);

    return $post;
}