<?php
if(!defined('ABSPATH')) exit;


add_filter('the_posts', 'fsr_extend_search_results', 10, 2);
add_filter('post_class', 'fsr_mark_placeholder_post', 10, 3);
/*
add_filter('post_link', 'fsr_virtual_permalink', 10, 2);
add_filter('page_link', 'fsr_virtual_permalink', 10, 2);
add_filter('post_type_link', 'fsr_virtual_permalink', 10, 2);
add_filter('get_permalink', 'fsr_virtual_permalink', 10, 2);
add_filter('the_permalink', 'fsr_virtual_permalink', 10, 2);*/

function fsr_extend_search_results($posts, $query) {
    if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return $posts;
    }
    $search = trim(get_search_query(false));
    if ($search === '') {
        return $posts;
    }
    $posts = array_merge(
        $posts,
        fsr_membercards_search($search),
        fsr_office_hours_search($search),
        fsr_dw_search($search)
    );
    return $posts;
}

function fsr_mark_placeholder_post($classes, $class, $post_id) {
    if ($post_id === -1) {
        $classes[] = 'search-placeholder';
    }
    return $classes;
}

function fsr_virtual_permalink($permalink, $post) {

    if (!is_search()) {
        return $permalink;
    }

    if (empty($GLOBALS['fsr_virtual_posts'])) {
        return $permalink;
    }

    // 🔥 WICHTIG: WP übergibt manchmal ID statt Objekt
    if (is_numeric($post)) {
        $post = get_post((int)$post);
    }

    if ($post instanceof WP_Post === false) {
        return $permalink;
    }

    $id = $post->ID;

    if (isset($GLOBALS['fsr_virtual_posts'][$id])) {
        return $GLOBALS['fsr_virtual_posts'][$id]['url'];
    }

    return $permalink;
}

function fsr_next_virtual_post_id() {
    static $id = -100000;
    return $id--;
}

function fsr_create_virtual_search_post(
    $title = '',
    $excerpt = '',
    $content = '',
    $url,
    $date,
    $type = 'page'
) {

    if ($content === '') {
        $content = $excerpt;
    }

    $type = post_type_exists($type) ? $type : 'page';

    $id = fsr_next_virtual_post_id();

    $GLOBALS['fsr_virtual_posts'][$id] = [
        'url'  => $url,
        'type' => $type,
        'date' => $date,
    ];
        echo '<p>';
        echo "DEBUG: Creating virtual post with ID $id, title: $title, url: $url, type: $type, date: $date";
        print_r($GLOBALS['fsr_virtual_posts'], true);
        echo '</p>';

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