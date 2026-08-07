<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/dw-admin.php';

add_action('init', 'fsr_etit_dokuwiki_rewrite_rules');
add_filter('query_vars', 'fsr_etit_dokuwiki_query_vars');
add_action('template_redirect', 'fsr_etit_dokuwiki_asset_proxy', 0);
add_filter('the_title', 'fsr_etit_dokuwiki_filter_title', 999, 2);
add_filter('the_content', 'fsr_etit_dokuwiki_filter_content', 999);
add_filter('pre_get_document_title', 'fsr_etit_dokuwiki_filter_document_title', 999);
add_action('admin_init', 'fsr_etit_dokuwiki_handle_cache_clear');
add_filter('posts_pre_query', 'fsr_etit_dokuwiki_create_virtual_post', 10, 2);
add_action('pre_get_posts', 'fsr_etit_dokuwiki_force_virtual_page_query');
add_filter('pre_get_shortlink', 'fsr_etit_dokuwiki_disable_shortlink', 10, 4);

function fsr_etit_dokuwiki_defaults(): array {
    return [
        'base_url'  => 'https://fsr-etit.de',
        'cache_time'=> 300,
        'start_page'=> 'aktuelles',
    ];
}

function fsr_etit_dokuwiki_normalize_base_url($url): string {
    $url = untrailingslashit(esc_url_raw(trim(fsr_etit_scalar_string($url)), ['https']));
    $parts = wp_parse_url($url);

    if (
        $url === '' ||
        !is_array($parts) ||
        empty($parts['host']) ||
        strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['query']) ||
        isset($parts['fragment'])
    ) {
        return '';
    }

    return $url;
}

function fsr_etit_dokuwiki_normalize_page_id($page): string {
    $page = sanitize_text_field(fsr_etit_scalar_string($page));
    $page = str_replace('\\', '/', $page);
    $page = preg_replace('/[^\p{L}\p{N}_:\/.\-]/u', '_', $page);
    $segments = preg_split('/[\/:]+/', (string) $page);
    $segments = array_values(array_filter($segments, static function ($segment): bool {
        return $segment !== '' && $segment !== '.' && $segment !== '..';
    }));

    return implode(':', $segments);
}

function fsr_etit_dokuwiki_sanitize_settings($input): array {
    $defaults = fsr_etit_dokuwiki_defaults();
    $input = is_array($input) ? wp_unslash($input) : [];
    $current = get_option(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS, []);
    $current = is_array($current) ? wp_parse_args($current, $defaults) : $defaults;

    $base_url = fsr_etit_dokuwiki_normalize_base_url($input['base_url'] ?? '');
    if ($base_url === '') {
        add_settings_error(
            FSR_ETIT_OPTION_DOKUWIKI_SETTINGS,
            'fsr_etit_invalid_dokuwiki_url',
            'Die DokuWiki-URL ist ungültig. Der bisherige Wert wurde beibehalten.',
            'error'
        );
        $base_url = fsr_etit_dokuwiki_normalize_base_url($current['base_url']) ?: $defaults['base_url'];
    }

    $start_page = fsr_etit_dokuwiki_normalize_page_id($input['start_page'] ?? '');
    if ($start_page === '') {
        $start_page = $defaults['start_page'];
    }

    return [
        'base_url'   => $base_url,
        'cache_time' => max(0, min(DAY_IN_SECONDS, absint($input['cache_time'] ?? $defaults['cache_time']))),
        'start_page' => $start_page,
    ];
}

function fsr_etit_dokuwiki_get_settings(): array {
    $defaults = fsr_etit_dokuwiki_defaults();
    $settings = get_option(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS, []);
    $settings = is_array($settings) ? wp_parse_args($settings, $defaults) : $defaults;

    $settings['base_url'] = fsr_etit_dokuwiki_normalize_base_url($settings['base_url']) ?: $defaults['base_url'];
    $settings['start_page'] = fsr_etit_dokuwiki_normalize_page_id($settings['start_page']) ?: $defaults['start_page'];
    $settings['cache_time'] = max(0, min(DAY_IN_SECONDS, absint($settings['cache_time'])));

    return $settings;
}

function fsr_etit_dokuwiki_is_same_origin(string $url, string $base_url): bool {
    $url_parts = wp_parse_url($url);
    $base_parts = wp_parse_url($base_url);
    if (!is_array($url_parts) || !is_array($base_parts)) {
        return false;
    }

    if (isset($url_parts['user']) || isset($url_parts['pass'])) {
        return false;
    }

    $url_scheme = strtolower((string) ($url_parts['scheme'] ?? ''));
    $base_scheme = strtolower((string) ($base_parts['scheme'] ?? ''));
    $url_host = strtolower((string) ($url_parts['host'] ?? ''));
    $base_host = strtolower((string) ($base_parts['host'] ?? ''));
    $url_port = (int) ($url_parts['port'] ?? ($url_scheme === 'https' ? 443 : 80));
    $base_port = (int) ($base_parts['port'] ?? ($base_scheme === 'https' ? 443 : 80));

    return $url_scheme === $base_scheme && $url_host === $base_host && $url_port === $base_port;
}

function fsr_etit_dokuwiki_get_title(): string {
    if (!fsr_etit_dokuwiki_is_request()) {
        return '';
    }

    $wiki = fsr_etit_dokuwiki_current_page();
    return is_array($wiki) ? (string) ($wiki['title'] ?? '') : '';
}

function fsr_etit_dokuwiki_is_request(): bool {
    return 1 === (int) get_query_var('dw_virtual');
}

function fsr_etit_dokuwiki_create_virtual_post($posts, $query) {
    if (is_admin() || !$query->is_main_query() || !fsr_etit_dokuwiki_is_request()) {
        return null;
    }

    $page = fsr_etit_dokuwiki_normalize_page_id(get_query_var('dw_page'));
    if ($page === '') {
        $page = fsr_etit_dokuwiki_get_settings()['start_page'];
    }

    $wiki = fsr_etit_dokuwiki_current_page();
    if (!is_array($wiki)) {
        return [];
    }

    return [new WP_Post((object) [
        'ID'                 => -200000,
        'post_title'         => $wiki['title'],
        'post_content'       => $wiki['content'],
        'post_status'        => 'publish',
        'post_type'          => 'page',
        'post_name'          => sanitize_title($page),
        'post_author'        => 0,
        'post_date'          => current_time('mysql'),
        'post_date_gmt'      => current_time('mysql', true),
        'post_modified'      => current_time('mysql'),
        'post_modified_gmt'  => current_time('mysql', true),
        'comment_status'     => 'closed',
        'ping_status'        => 'closed',
        'filter'             => 'raw',
    ])];
}

function fsr_etit_dokuwiki_force_virtual_page_query($query): void {
    if (is_admin() || !$query->is_main_query() || !fsr_etit_dokuwiki_is_request()) {
        return;
    }

    $query->is_home = false;
    $query->is_archive = false;
    $query->is_404 = false;
    $query->is_page = true;
    $query->is_singular = true;
    $query->set('post_type', 'page');
    $query->set('posts_per_page', 1);
}

function fsr_etit_dokuwiki_filter_content($content): string {
    if (!fsr_etit_dokuwiki_is_request() || !in_the_loop() || !is_main_query()) {
        return (string) $content;
    }

    $wiki = fsr_etit_dokuwiki_current_page();
    if (!is_array($wiki) || empty($wiki['content'])) {
        return '<p>Wiki konnte nicht geladen werden.</p>';
    }

    return '<div class="dw-content">' . wp_kses_post($wiki['content']) . '</div>';
}

function fsr_etit_dokuwiki_filter_document_title($title): string {
    if (!fsr_etit_dokuwiki_is_request()) {
        return (string) $title;
    }

    return fsr_etit_dokuwiki_get_title() ?: (string) $title;
}

function fsr_etit_dokuwiki_filter_title($title, $post_id): string {
    if (!in_the_loop() || !is_main_query() || !fsr_etit_dokuwiki_is_request()) {
        return (string) $title;
    }

    return fsr_etit_dokuwiki_get_title() ?: (string) $title;
}

function fsr_etit_dokuwiki_disable_shortlink($shortlink, $id, $context, $allow_slugs): string {
    return fsr_etit_dokuwiki_is_request() ? '' : (string) $shortlink;
}

function fsr_etit_dokuwiki_query_vars($vars): array {
    $vars[] = 'dw_page';
    $vars[] = 'dw_virtual';
    return array_values(array_unique($vars));
}

function fsr_etit_dokuwiki_fetch($page) {
    $settings = fsr_etit_dokuwiki_get_settings();
    $page = fsr_etit_dokuwiki_normalize_page_id($page) ?: $settings['start_page'];
    $cache_version = absint(get_option(FSR_ETIT_OPTION_DOKUWIKI_CACHE_VERSION, 1));
    $cache_key = 'fsr_etit_dokuwiki_' . md5(
        $cache_version . '|' . $settings['base_url'] . '|' . $settings['cache_time'] . '|' . $page
    );

    if ($settings['cache_time'] > 0) {
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['title'], $cached['content'])) {
            return $cached;
        }
    }

    $url = add_query_arg(
        ['id' => $page, 'do' => 'export_xhtmlbody'],
        $settings['base_url'] . '/doku.php'
    );

    if (!fsr_etit_dokuwiki_is_same_origin($url, $settings['base_url'])) {
        return false;
    }

    $response = wp_safe_remote_get($url, [
        'timeout'             => 12,
        'redirection'         => 3,
        'limit_response_size' => 2 * MB_IN_BYTES,
        'user-agent'          => 'FSR-ETIT-Website-Tools/' . FSR_ETIT_VERSION,
        'headers'             => [
            'Cache-Control' => 'no-cache',
            'Pragma'        => 'no-cache',
        ],
    ]);

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        fsr_etit_log('DokuWiki-Seite konnte nicht geladen werden.');
        return false;
    }

    $html = wp_remote_retrieve_body($response);
    if (!is_string($html) || $html === '') {
        return false;
    }

    $wiki = fsr_etit_dokuwiki_transform($html);
    if (!is_array($wiki)) {
        return false;
    }

    if ($settings['cache_time'] > 0) {
        set_transient($cache_key, $wiki, $settings['cache_time']);
    }

    return $wiki;
}

function fsr_etit_dokuwiki_rewrite_rules(): void {
    add_rewrite_rule('^wiki/?$', 'index.php?dw_virtual=1', 'top');
    add_rewrite_rule('^wiki/(.+?)/?$', 'index.php?dw_virtual=1&dw_page=$matches[1]', 'top');
}

function fsr_etit_dokuwiki_current_page() {
    static $loaded = false;
    static $wiki = null;

    if (!$loaded) {
        $loaded = true;
        $wiki = fsr_etit_dokuwiki_fetch(get_query_var('dw_page'));
    }

    return $wiki;
}

function fsr_etit_dokuwiki_search($search_term): array {
    $search_term = trim(wp_strip_all_tags((string) $search_term));
    if ($search_term === '') {
        return [];
    }

    $settings = fsr_etit_dokuwiki_get_settings();
    $cache_version = absint(get_option(FSR_ETIT_OPTION_DOKUWIKI_CACHE_VERSION, 1));
    $cache_key = 'fsr_etit_dokuwiki_search_' . md5(
        $cache_version . '|' . $settings['base_url'] . '|' . fsr_etit_lowercase($search_term)
    );
    $items = get_transient($cache_key);

    if (!is_array($items)) {
        $url = add_query_arg(
            [
                'do' => 'search',
                'id' => 'protokolle:sitzungsprotokolle',
                'sf' => 1,
                'q'  => $search_term . ' @protokolle',
                'srt'=> 'mtime',
            ],
            $settings['base_url'] . '/doku.php'
        );

        if (!fsr_etit_dokuwiki_is_same_origin($url, $settings['base_url'])) {
            return [];
        }

        $response = wp_safe_remote_get($url, [
            'timeout'             => 15,
            'redirection'         => 3,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'user-agent'          => 'FSR-ETIT-Website-Tools/' . FSR_ETIT_VERSION,
        ]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        $items = fsr_etit_dokuwiki_parse_search_results((string) $html);
        set_transient($cache_key, $items, 5 * MINUTE_IN_SECONDS);
    }

    $posts = [];
    foreach ($items as $item) {
        $posts[] = fsr_etit_create_virtual_post(
            $item['title'] ?? '',
            $item['excerpt'] ?? '',
            $item['excerpt'] ?? '',
            $item['url'] ?? home_url('/wiki/'),
            $item['date'] ?? '',
            'page'
        );
    }

    return $posts;
}

function fsr_etit_dokuwiki_parse_search_results(string $html): array {
    if ($html === '' || !class_exists('DOMDocument')) {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML(
        '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $items = [];
    $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' search_fullpage_result ')]");
    if ($nodes === false) {
        return [];
    }

    foreach ($nodes as $result) {
        if (count($items) >= 100) {
            break;
        }
        $link = $result->getElementsByTagName('a')->item(0);
        if (!$link) {
            continue;
        }

        $page = $link->getAttribute('data-wiki-id');
        if ($page === '') {
            $query = [];
            $href_query = wp_parse_url($link->getAttribute('href'), PHP_URL_QUERY);
            if (is_string($href_query)) {
                parse_str($href_query, $query);
            }
            $page = $query['id'] ?? '';
        }

        $page = fsr_etit_dokuwiki_normalize_page_id($page);
        if ($page === '') {
            continue;
        }

        $title = basename(str_replace(':', '/', $page));
        $title = ucwords(str_replace('_', ' ', $title));
        $snippet = $xpath->query(".//dd[contains(concat(' ', normalize-space(@class), ' '), ' snippet ')]", $result);
        $excerpt = $snippet && $snippet->item(0)
            ? preg_replace('/\s+/u', ' ', trim($snippet->item(0)->textContent))
            : '';
        $time = $result->getElementsByTagName('time')->item(0);

        $items[] = [
            'title'   => sanitize_text_field($title),
            'excerpt' => sanitize_textarea_field((string) $excerpt),
            'url'     => home_url('/wiki/' . str_replace(':', '/', $page)),
            'date'    => $time ? sanitize_text_field($time->getAttribute('datetime')) : '',
        ];
    }

    return $items;
}

function fsr_etit_dokuwiki_handle_cache_clear(): void {
    if (!isset($_POST['dw_clear_cache'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Du hast keine Berechtigung für diese Aktion.', 'fsr-etit-website-tools'));
    }

    check_admin_referer('fsr_etit_dokuwiki_settings-options');

    $cache_version = absint(get_option(FSR_ETIT_OPTION_DOKUWIKI_CACHE_VERSION, 1));
    update_option(FSR_ETIT_OPTION_DOKUWIKI_CACHE_VERSION, $cache_version + 1, false);

    global $wpdb;
    $value_like = $wpdb->esc_like('_transient_fsr_etit_dokuwiki_') . '%';
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $value_like
        )
    );
    foreach ((array) $option_names as $option_name) {
        $transient_name = str_replace('_transient_', '', fsr_etit_scalar_string($option_name));
        if (str_starts_with($transient_name, 'fsr_etit_dokuwiki_')) {
            delete_transient($transient_name);
        }
    }

    add_settings_error(
        FSR_ETIT_OPTION_DOKUWIKI_SETTINGS,
        'fsr_etit_dokuwiki_cache_cleared',
        'Der DokuWiki-Cache wurde gelöscht.',
        'updated'
    );
}

function fsr_etit_dokuwiki_transform(string $html) {
    if (!class_exists('DOMDocument')) {
        return [
            'title'   => '',
            'content' => wp_kses_post($html),
        ];
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML(
        '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return false;
    }

    $title = '';
    $h1 = $dom->getElementsByTagName('h1')->item(0);
    if ($h1) {
        $title = sanitize_text_field(trim($h1->textContent));
        $h1->parentNode->removeChild($h1);
    }

    $settings = fsr_etit_dokuwiki_get_settings();
    $base_url = $settings['base_url'];

    foreach ($dom->getElementsByTagName('a') as $link) {
        $href = html_entity_decode(trim($link->getAttribute('href')), ENT_QUOTES, 'UTF-8');
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            continue;
        }

        $query = wp_parse_url($href, PHP_URL_QUERY);
        if (str_contains($href, 'doku.php') && is_string($query)) {
            parse_str($query, $params);
            $page = fsr_etit_dokuwiki_normalize_page_id($params['id'] ?? '');
            if ($page !== '') {
                $link->setAttribute('href', home_url('/wiki/' . str_replace(':', '/', $page)));
                fsr_etit_dokuwiki_maybe_mark_private_link($link, $page);
            }
            continue;
        }

        if (str_starts_with($href, '/wiki/')) {
            $page = fsr_etit_dokuwiki_normalize_page_id(substr(strtok($href, '?'), strlen('/wiki/')));
            fsr_etit_dokuwiki_maybe_mark_private_link($link, $page);
            continue;
        }

        $scheme = wp_parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null && !str_starts_with($href, '/')) {
            $page = fsr_etit_dokuwiki_normalize_page_id(strtok($href, '?'));
            if ($page !== '') {
                $link->setAttribute('href', home_url('/wiki/' . str_replace(':', '/', $page)));
                fsr_etit_dokuwiki_maybe_mark_private_link($link, $page);
            }
        }
    }

    foreach ($dom->getElementsByTagName('img') as $image) {
        $src = html_entity_decode(trim($image->getAttribute('src')), ENT_QUOTES, 'UTF-8');
        if ($src === '') {
            continue;
        }

        $src = fsr_etit_dokuwiki_resolve_asset_url($src, $base_url);

        if ($src !== '' && fsr_etit_dokuwiki_is_same_origin($src, $base_url)) {
            $proxy_url = fsr_etit_dokuwiki_asset_proxy_url($src);
            if ($proxy_url !== '') {
                $image->setAttribute('src', $proxy_url);
            } else {
                $image->removeAttribute('src');
            }
        } elseif ($src === '' || 'https' !== strtolower((string) wp_parse_url($src, PHP_URL_SCHEME))) {
            $image->removeAttribute('src');
        }

        $image->removeAttribute('srcset');
        $classes = trim($image->getAttribute('class') . ' dw-attached-image');
        $image->setAttribute('class', $classes);
        $image->setAttribute('loading', 'lazy');
        $image->setAttribute('decoding', 'async');
    }

    $tables = [];
    foreach ($dom->getElementsByTagName('table') as $table) {
        $tables[] = $table;
    }
    foreach ($tables as $table) {
        $wrapper = $dom->createElement('div');
        $wrapper->setAttribute('class', 'dw-table');
        $table->parentNode->insertBefore($wrapper, $table);
        $wrapper->appendChild($table);
    }

    $xpath = new DOMXPath($dom);
    $levels = $xpath->query("//div[starts-with(@class,'level')]");
    if ($levels !== false) {
        $nodes = [];
        foreach ($levels as $level) {
            $nodes[] = $level;
        }
        foreach ($nodes as $level) {
            fsr_etit_dokuwiki_unwrap($level);
        }
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $content = $body ? fsr_etit_dokuwiki_inner_html($body) : '';

    return [
        'title'   => $title,
        'content' => wp_kses_post($content),
    ];
}

function fsr_etit_dokuwiki_maybe_mark_private_link(DOMElement $link, string $page): void {
    $page = fsr_etit_lowercase($page);
    if (!str_contains($page, 'nonpublic') && !str_contains($page, 'intern')) {
        return;
    }

    $link->setAttribute('target', '_blank');
    $link->setAttribute('rel', 'noopener noreferrer');
}

function fsr_etit_dokuwiki_asset_proxy_url(string $url): string {
    $url = esc_url_raw($url, ['https']);
    if ($url === '') {
        return '';
    }

    $expires = time() + DAY_IN_SECONDS + HOUR_IN_SECONDS;
    return add_query_arg(
        [
            'dw_asset' => $url,
            'dw_exp'   => $expires,
            'dw_sig'   => hash_hmac('sha256', $url . '|' . $expires, wp_salt('nonce')),
        ],
        home_url('/')
    );
}

function fsr_etit_dokuwiki_resolve_asset_url(string $url, string $base_url): string {
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $base = wp_parse_url($base_url);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return strtolower((string) $base['scheme']) . ':' . $url;
    }

    if (str_starts_with($url, '/')) {
        $origin = strtolower((string) $base['scheme']) . '://' . strtolower((string) $base['host']);
        if (!empty($base['port'])) {
            $origin .= ':' . (int) $base['port'];
        }
        return $origin . $url;
    }

    return trailingslashit($base_url) . ltrim($url, '/');
}

function fsr_etit_dokuwiki_asset_proxy(): void {
    if (!isset($_GET['dw_asset'], $_GET['dw_exp'], $_GET['dw_sig'])) {
        return;
    }

    $url = esc_url_raw(fsr_etit_scalar_string(wp_unslash($_GET['dw_asset'])), ['https']);
    $expires = absint(fsr_etit_scalar_string(wp_unslash($_GET['dw_exp'])));
    $signature = sanitize_text_field(fsr_etit_scalar_string(wp_unslash($_GET['dw_sig'])));
    $expected = hash_hmac('sha256', $url . '|' . $expires, wp_salt('nonce'));
    $settings = fsr_etit_dokuwiki_get_settings();

    if (
        $expires < time() ||
        $expires > time() + (2 * DAY_IN_SECONDS) ||
        !hash_equals($expected, $signature) ||
        !fsr_etit_dokuwiki_is_same_origin($url, $settings['base_url'])
    ) {
        status_header(403);
        exit;
    }

    $response = wp_safe_remote_get($url, [
        'timeout'             => 15,
        'redirection'         => 3,
        'limit_response_size' => 5 * MB_IN_BYTES,
        'user-agent'          => 'FSR-ETIT-Website-Tools/' . FSR_ETIT_VERSION,
    ]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        status_header(404);
        exit;
    }

    $content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
    $content_type = trim(explode(';', $content_type, 2)[0]);
    $allowed_types = [
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/svg+xml',
        'image/webp',
    ];
    if (!in_array($content_type, $allowed_types, true)) {
        status_header(415);
        exit;
    }

    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if ($content_type === 'image/svg+xml') {
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    }
    header('Content-Type: ' . $content_type);
    echo wp_remote_retrieve_body($response);
    exit;
}

function fsr_etit_dokuwiki_inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function fsr_etit_dokuwiki_unwrap(DOMNode $element): void {
    if (!$element->parentNode) {
        return;
    }

    while ($element->firstChild) {
        $element->parentNode->insertBefore($element->firstChild, $element);
    }
    $element->parentNode->removeChild($element);
}
