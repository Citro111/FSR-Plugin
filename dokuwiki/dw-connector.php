<?php
if (!defined('ABSPATH')) exit;

// Hooks für DokuWiki
add_action('init', 'fsr_dw_rewrite_rules');
add_filter('query_vars', 'fsr_dw_query_vars');
add_action('init', 'fsr_dw_asset_proxy');
add_filter('the_title', 'fsr_dw_filter_title', 999, 2);
add_filter('the_content', 'fsr_dw_the_content', 999);
add_filter('pre_get_document_title', 'fsr_dw_filter_document_title', 999);
add_action('admin_init', 'fsr_dw_handle_cache_clear');
add_filter('the_posts', 'fsr_dw_create_virtual_post', 10, 2);
add_action('pre_get_posts', 'fsr_dw_force_virtual_page_query');


require_once __DIR__ . '/dw-admin.php';


function fsr_dw_get_title() {
    if (!fsr_dw_is_wiki_request()) {
        return '';
    }
    $wiki = fsr_dw_current_page();
    if (!is_array($wiki)) {
        return '';
    }
    return !empty($wiki['title'])
        ? $wiki['title'] : '';
}

function fsr_dw_is_wiki_request(): bool {
    return ( get_query_var('dw_virtual') == 1 && get_query_var('dw_page') !== null );
}

function fsr_dw_create_virtual_post($posts, $query) {

    if (is_admin() || !$query->is_main_query()) {
        return $posts;
    }

    if (get_query_var('dw_virtual') != 1) {
        return $posts;
    }

    $page = get_query_var('dw_page');

    if (!$page) {
        $page = fsr_dw_get_settings()['start_page'];
    }

    $wiki = fsr_dw_fetch($page);

    if (!$wiki) {
        return $posts;
    }

    $virtual = new WP_Post((object)[
        'ID' => -200000,
        'post_title' => $wiki['title'],
        'post_content' => $wiki['content'],
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_name' => sanitize_title($page),
        'post_author' => 0,
        'post_date' => current_time('mysql'),
        'post_date_gmt' => current_time('mysql', true),
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', true),
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'filter' => 'raw'
    ]);

    $posts = [$virtual];

    // wichtig:
    global $wp_query;
    $wp_query->post = $virtual;
    $wp_query->posts = $posts;
    $wp_query->queried_object = $virtual;
    $wp_query->queried_object_id = $virtual->ID;
    $wp_query->post_count = 1;

    do_action('qm/debug', [
        'DW Virtual Debug' => [
            'post' => isset($wp_query->post) ? get_class($wp_query->post) : 'kein post',
            'queried_object' => isset($wp_query->queried_object) ? get_class($wp_query->queried_object) : 'kein object',
            'queried_id' => $wp_query->queried_object_id ?? null,
            'post_type' => isset($wp_query->post->post_type) ? $wp_query->post->post_type : 'kein post_type'
        ]
    ]);
    
    global $post;
    do_action('qm/debug', [
        'Global Post' => is_object($post)
            ? [
                'class' => get_class($post),
                'id' => $post->ID,
                'type' => $post->post_type,
            ]
            : 'NULL'
    ]);
    return $posts;
}


function fsr_dw_force_virtual_page_query($query) {

    if (
        is_admin() ||
        !$query->is_main_query()
    ) {
        return;
    }

    if (get_query_var('dw_virtual') != 1) {
        return;
    }

    $query->is_home = false;
    $query->is_archive = false;

    $query->is_page = true;
    $query->is_singular = true;

    $query->set('post_type', 'page');
    $query->set('posts_per_page', 1);
}

function fsr_dw_the_content($content) {

    do_action('qm/debug', [
        'DW Content Filter' => 'ausgeführt',
        'Zeit' => current_time('mysql')
    ]);
    $page = get_query_var('dw_page', null);
    if ($page === null) {
        return $content;
    }

    $wiki = fsr_dw_current_page();

    if (!is_array($wiki)) {
        return '<p>Wiki konnte nicht geladen werden.</p>';
    }

    if (empty($wiki['content'])) {
        return '<p>Wiki konnte nicht geladen werden.</p>';
    }

    return '<div class="dw-content">' . $wiki['content'] . '</div>';
}

function fsr_dw_filter_document_title($title) {
    if (!fsr_dw_is_wiki_request()) {
        return $title;
    }
    $wiki = fsr_dw_current_page();
    if (!is_array($wiki)) {
        return $title;
    }
    return $wiki['title'] ?: $title;
}

function fsr_dw_filter_title($title, $post_id) {

    if (
        !in_the_loop()
        ||
        !is_main_query()
        ||
        !fsr_dw_is_wiki_request()
    ) {
        return $title;
    }

    return fsr_dw_get_title() ?: $title;
}

function fsr_dw_get_settings() {
    return wp_parse_args(get_option('dw_bridge_settings', []), [
        'base_url' => 'https://fsr-etit.de',
        'cache_time' => 100,
        'start_page' => 'aktuelles'
    ]);
}


function fsr_dw_query_vars($vars) {
    $vars[] = 'dw_page';
    $vars[] = 'dw_virtual';
    return $vars;
}
function fsr_dw_fetch($page) {
    $s = fsr_dw_get_settings();
    if (!$page) {
        $page = $s['start_page'];
    }
    $cache_key = 'dw_cacheKey_' . md5($page);
    $cache_time = intval($s['cache_time']);
    if ($cache_time > 0) {
        $cached = get_transient($cache_key);
        $timeout = get_option('_transient_timeout_' . $cache_key);
        do_action('qm/debug', [
            'Transient vorhanden' => $cached !== false,
            'Transient Key' => $cache_key,
            'Transient Ablauf' => $timeout ? date('Y-m-d H:i:s', $timeout) : 'kein Ablauf',
            'Transient Länge' => is_string($cached) ? strlen($cached) : gettype($cached)
        ]);
        if ($cached !== false) {
            do_action('qm/debug', [
                'DW Cache' => 'Treffer',
                'Page' => $page
            ]);
            return $cached;
        }
    }
    do_action('qm/debug', [
        'DW Cache' => 'Nicht vorhanden, lade neu',
        'Cache-Dauer' => $s['cache_time'],
        'Page' => $page
    ]);
    $url = rtrim($s['base_url'], '/') .
        '/doku.php?id=' . urlencode($page) .
        '&do=export_xhtmlbody';
    do_action('qm/debug', [
        'DW Request URL' => $url
    ]);
    $res = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => [
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache'
        ]
    ]);
    if (is_wp_error($res)) {

        do_action('qm/error', [
            'DW Fehler' => $res->get_error_message()
        ]);

        return false;
    }
    $html = wp_remote_retrieve_body($res);
    if (!$html) {
        return false;
    }
    $html = fsr_dw_transform($html);
    if($cache_time > 0) {
        set_transient(
            $cache_key,
            $html,
            intval($s['cache_time'])
        );
    }
    return $html;
}

function fsr_dw_rewrite_rules() {
    add_rewrite_rule(
        '^wiki/?$',
        'index.php?dw_virtual=1',
        'top'
    );
    add_rewrite_rule(
        '^wiki/(.+)/?$',
        'index.php?dw_virtual=1&dw_page=$matches[1]',
        'top'
    );
}

function fsr_dw_current_page() {
    static $wiki = null;
    if ($wiki === null) {
        $wiki = fsr_dw_fetch(get_query_var('dw_page'));
    }

    return $wiki;
}

function fsr_dw_search($search_term) {

    $search_term = trim($search_term);

    if ($search_term === '') {
        return [];
    }

    $settings = fsr_dw_get_settings();

    $url =
        rtrim($settings['base_url'], '/') .
        '/aktuelles?do=search&id=' .
        urlencode('protokolle:sitzungsprotokolle') .
        '&sf=1&q=' .
        urlencode($search_term . ' @protokolle') .
        '&srt=mtime';

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'user-agent' => 'Mozilla/5.0'
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $html = wp_remote_retrieve_body($response);

    if (!$html) {
        return [];
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    $xpath = new DOMXPath($dom);

    $virtual_posts = [];

    foreach ($xpath->query("//div[contains(@class,'search_fullpage_result')]") as $result) {

        // ---------------- Link ----------------

        $link = $result->getElementsByTagName('a')->item(0);

        if (!$link) {
            continue;
        }

        $href = $link->getAttribute('href');

        // ---------------- Titel ----------------

        $page = $link->getAttribute('data-wiki-id');

        if ($page === '') {

            parse_str(parse_url($href, PHP_URL_QUERY), $query);

            $page = $query['id'] ?? '';
        }

        $title = basename(str_replace(':', '/', $page));
        $title = str_replace('_', ' ', $title);
        $title = str_replace('ae', 'ä', $title);
        $title = str_replace('oe', 'ö', $title);
        $title = str_replace('ue', 'ü', $title);
        $title = ucwords($title);

        // ---------------- URL ----------------

        $url = home_url('/wiki/' . $page);

        // ---------------- Snippet ----------------

        $excerpt = '';

        $snippet = $xpath->query(".//dd[contains(@class,'snippet')]", $result)->item(0);

        if ($snippet) {

            $excerpt = trim(
                preg_replace('/\s+/', ' ', strip_tags($snippet->textContent))
            );
        }

        // ---------------- Datum ----------------

        $date = '';

        $time = $result->getElementsByTagName('time')->item(0);

        if ($time) {

            $date = $time->getAttribute('datetime');
        }

        $virtual_posts[] = fsr_create_virtual_search_post(
            $title,
            $excerpt,
            $excerpt,
            $url,
            $date,
            'page'
        );
    }

    return $virtual_posts;
}

function fsr_dw_handle_cache_clear() {
    if (!isset($_POST['dw_clear_cache'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $wpdb->query(
        "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_dw_%'
        OR option_name LIKE '_transient_timeout_dw_%'
        "
    );
    add_settings_error(
        'dw_bridge_settings',
        'dw_cache_cleared',
        'DokuWiki Cache wurde gelöscht.',
        'updated'
    );
}

function fsr_dw_transform($html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $title = '';
    $h1 = $dom->getElementsByTagName('h1')->item(0);
    if ($h1) {
        $title = trim($h1->textContent);

        // H1 aus dem Content entfernen
        $h1->parentNode->removeChild($h1);
    }
    $s = fsr_dw_get_settings();
    $base_url = rtrim($s['base_url'], '/');
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href')); if (!$href) continue; $clean_href = strtok($href, '?');
        $open_in_new_tab = false;
        if (!str_starts_with($href, 'http') && !str_starts_with($href, '#') && !str_starts_with($href, '/wiki/')) {
            if (!str_contains($href, '/') || str_contains($href, ':')) {
                $clean_href = ltrim($clean_href, ':');
                $target_page = ltrim($clean_href, '/');
                $a->setAttribute('href', home_url('/wiki/' . $target_page));
                $open_in_new_tab = fsr_dw_should_open_in_new_tab($target_page);
                if ($open_in_new_tab) {
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', 'noopener noreferrer');
                }
                continue;
            }
        }
        if (str_contains($href, 'doku.php?id=')) {
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            if (!empty($query['id'])) {
                $page = ltrim($query['id'], ':');
                $a->setAttribute('href', home_url('/wiki/' . ltrim($page, '/')));
                $open_in_new_tab = fsr_dw_should_open_in_new_tab($page);
                if ($open_in_new_tab) {
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
        if (str_starts_with($href, '/wiki/')) {
            $page = ltrim(substr($clean_href, strlen('/wiki/')), '/');
            if (fsr_dw_should_open_in_new_tab($page)) {
                $a->setAttribute('target', '_blank');
                $a->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    foreach ($dom->getElementsByTagName('img') as $img) {
        $src = trim($img->getAttribute('src'));
        if (!$src) continue;
        $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
        if (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) {
            $src = $base_url . '/' . ltrim($src, '/');
        }
        $img->setAttribute('src', home_url('/?dw_asset=' . urlencode($src)));
        $current_class = $img->getAttribute('class');
        $img->setAttribute('class', $current_class . ' dw-attached-image');
        $img->setAttribute('loading', 'lazy');
    }

    foreach ($dom->getElementsByTagName('table') as $table) {
        $wrapper = $dom->createElement('div');
        $wrapper->setAttribute('class', 'dw-table');
        $table->parentNode->insertBefore($wrapper, $table);
        $wrapper->appendChild($table);
    }

    $xpath = new DOMXPath($dom);
    foreach ($xpath->query("//div[starts-with(@class,'level')]") as $div) {
        fsr_dw_unwrap($div);
    }
    return [
        'title'   => $title,
        'content' => $dom->saveHTML(),
    ];
}

function fsr_dw_should_open_in_new_tab($page) {
    $page = strtolower(trim((string) $page));
    if ($page === '') {
        return false;
    }

    return (strpos($page, 'nonpublic') !== false || strpos($page, 'intern') !== false);
}

function fsr_dw_asset_proxy() {
    if (!isset($_GET['dw_asset'])) return;
    $url = rawurldecode($_GET['dw_asset']);
    $s = fsr_dw_get_settings(); $base = rtrim($s['base_url'], '/');
    if (strpos($url, $base) !== 0) { status_header(403); exit; }
    $res = wp_remote_get($url, [ 'timeout' => 15, 'user-agent' => 'Mozilla/5.0' ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        status_header(404); exit;
    }
    $content_type = wp_remote_retrieve_header($res, 'content-type');
    if ($content_type) header('Content-Type: ' . $content_type);
    echo wp_remote_retrieve_body($res); exit;
}

function fsr_dw_unwrap($element) {
    while ($element->firstChild) {
        $element->parentNode->insertBefore(
            $element->firstChild,
            $element
        );
    }
    $element->parentNode->removeChild($element);
}