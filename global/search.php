<?php
if(!defined('ABSPATH')) exit;


add_filter('the_posts', 'fsr_extend_search_results', 10, 2);
add_filter('post_class', 'fsr_mark_placeholder_post', 10, 3);
add_filter('post_type_link', 'fsr_virtual_permalink', 10, 2);
add_filter('page_link', 'fsr_virtual_permalink', 10, 2);
add_filter('post_link', 'fsr_virtual_permalink', 10, 2);

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
    usort($posts, function($a, $b) {
        return strtotime($b->post_date)
            <=>
            strtotime($a->post_date);
    });
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
    $post_id = is_object($post) ? (int) $post->ID : (int) $post;
    if (isset($GLOBALS['fsr_virtual_posts'][$post_id])) {
        return $GLOBALS['fsr_virtual_posts'][$post_id]['url'];
    }
    return $permalink;
}

function fsr_next_virtual_post_id() {
    static $id = -100000;
    return $id--;
}