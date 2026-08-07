<?php

if (!defined('ABSPATH')) {
    exit;
}

add_filter('the_posts', 'fsr_etit_extend_search_results', 10, 2);
add_filter('post_class', 'fsr_etit_mark_virtual_post', 10, 3);
add_filter('post_type_link', 'fsr_etit_virtual_permalink', 10, 2);
add_filter('page_link', 'fsr_etit_virtual_permalink', 10, 2);
add_filter('post_link', 'fsr_etit_virtual_permalink', 10, 2);

function fsr_etit_extend_search_results($posts, $query): array {
    $posts = is_array($posts) ? $posts : [];
    if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return $posts;
    }

    $search = trim(wp_strip_all_tags(get_search_query(false)));
    if ($search === '') {
        return $posts;
    }

    $providers = [
        'fsr_etit_membercards_search',
        'fsr_etit_office_hours_search',
        'fsr_etit_calendar_search',
        'fsr_etit_dokuwiki_search',
    ];

    foreach ($providers as $provider) {
        if (!is_callable($provider)) {
            continue;
        }

        $results = call_user_func($provider, $search);
        if (is_array($results)) {
            $posts = array_merge($posts, $results);
        }
    }

    usort($posts, static function ($a, $b): int {
        $date_a = isset($a->post_date) ? strtotime((string) $a->post_date) : false;
        $date_b = isset($b->post_date) ? strtotime((string) $b->post_date) : false;
        return (int) $date_b <=> (int) $date_a;
    });

    return $posts;
}

function fsr_etit_mark_virtual_post($classes, $class, $post_id): array {
    $classes = is_array($classes) ? $classes : [];
    if (isset($GLOBALS['fsr_etit_virtual_posts'][(int) $post_id])) {
        $classes[] = 'fsr-search-virtual-result';
    }

    return array_values(array_unique($classes));
}

function fsr_etit_virtual_permalink($permalink, $post): string {
    if (empty($GLOBALS['fsr_etit_virtual_posts'])) {
        return (string) $permalink;
    }

    $post_id = is_object($post) ? (int) $post->ID : (int) $post;
    if (isset($GLOBALS['fsr_etit_virtual_posts'][$post_id])) {
        return $GLOBALS['fsr_etit_virtual_posts'][$post_id]['url'];
    }

    return (string) $permalink;
}

function fsr_etit_next_virtual_post_id(): int {
    static $id = -100000;
    return $id--;
}
