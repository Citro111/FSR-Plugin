<?php
/**
 * FSR ET/IT Link Marker
 *
 * Marks:
 * - internal links which resolve to HTTP 404
 * - internal WordPress pages which are effectively heading-only
 * - links to the old fsr-etit.de host (admin-only by default)
 *
 * This module is intentionally self-contained. It does not create admin pages
 * or settings yet; the host plugin can add those later via the provided filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FSR_ETIT_LINK_MARKER_VERSION', '1.1.0');
define(
    'FSR_ETIT_LINK_MARKER_DIR',
    plugin_dir_path(__FILE__)
);
define(
    'FSR_ETIT_LINK_MARKER_URL',
    plugin_dir_url(__FILE__)
);

require_once FSR_ETIT_LINK_MARKER_DIR . 'admin.php';

/**
 * Enqueue frontend assets.
 */
add_action('wp_enqueue_scripts', 'fsr_etit_link_marker_enqueue_assets');

function fsr_etit_link_marker_enqueue_assets(): void {
    if (is_admin()) {
        return;
    }

    wp_enqueue_style(
        'fsr-etit-link-marker',
        FSR_ETIT_LINK_MARKER_URL . 'assets/link-marker.css',
        [],
        FSR_ETIT_LINK_MARKER_VERSION
    );

    wp_enqueue_script(
        'fsr-etit-link-marker',
        FSR_ETIT_LINK_MARKER_URL . 'assets/link-marker.js',
        [],
        FSR_ETIT_LINK_MARKER_VERSION,
        true
    );

    wp_localize_script(
        'fsr-etit-link-marker',
        'FSREtitLinkMarker',
        [
            'endpoint' => esc_url_raw(
                rest_url('fsr-etit/v1/link-status')
            ),
            'siteHost' => wp_parse_url(home_url('/'), PHP_URL_HOST) ?: '',
            'sitePath' => wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
            'oldUrls'  => fsr_etit_link_marker_get_old_urls(),
            'showOldForCurrentUser' => fsr_etit_link_marker_can_show('old'),
            'showMissingForCurrentUser' => fsr_etit_link_marker_can_show('missing'),
            'showEmptyForCurrentUser' => fsr_etit_link_marker_can_show('empty'),
            'batchSize' => 50,
        ]
    );
}

/**
 * Registers a public read-only REST endpoint.
 *
 * The endpoint only receives URLs from the current site and returns a compact
 * classification. Results are cached server-side to avoid repeated checks.
 */
add_action('rest_api_init', 'fsr_etit_link_marker_register_rest_routes');

function fsr_etit_link_marker_register_rest_routes(): void {
    register_rest_route(
        'fsr-etit/v1',
        '/link-status',
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'fsr_etit_link_marker_rest_status',
            'permission_callback' => '__return_true',
            'args'                => [
                'urls' => [
                    'required'          => true,
                    'type'              => 'array',
                    'maxItems'          => 50,
                    'items'             => [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                    'sanitize_callback' => static function ($urls): array {
                        if (!is_array($urls)) {
                            return [];
                        }

                        $result = [];
                        foreach ($urls as $url) {
                            $url = esc_url_raw((string) $url, ['http', 'https']);
                            if ($url !== '') {
                                $result[] = $url;
                            }
                        }

                        return array_values(array_unique($result));
                    },
                ],
            ],
        ]
    );
}

/**
 * Classifies a batch of URLs.
 */
function fsr_etit_link_marker_rest_status(WP_REST_Request $request): WP_REST_Response {
    $urls = $request->get_param('urls');
    $urls = is_array($urls) ? $urls : [];

    $statuses = [];

    foreach ($urls as $url) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if ($url === '') {
            continue;
        }

        $statuses[$url] = fsr_etit_link_marker_classify_url($url);
    }

    return new WP_REST_Response(
        [
            'statuses' => $statuses,
        ],
        200,
        [
            'Cache-Control' => 'private, max-age=60',
        ]
    );
}

/**
 * Removes a URL fragment because fragments do not affect the HTTP target.
 */
function fsr_etit_link_marker_strip_fragment(string $url): string {
    $hash_pos = strpos($url, '#');
    return $hash_pos === false ? $url : substr($url, 0, $hash_pos);
}

/**
 * Checks whether a URL belongs to one of the configured legacy base URLs.
 * Matching ignores the scheme but respects host, port and optional base path.
 */
function fsr_etit_link_marker_is_old_url(string $url): bool {
    $target = wp_parse_url($url);
    if (!is_array($target) || empty($target['host'])) {
        return false;
    }

    $target_host = strtolower((string) $target['host']);
    $target_scheme = strtolower((string) ($target['scheme'] ?? ''));
    $target_port_raw = isset($target['port']) ? (int) $target['port'] : null;
    $target_port = in_array([$target_scheme, $target_port_raw], [['http', 80], ['https', 443]], true)
        ? null
        : $target_port_raw;
    $target_path = '/' . ltrim((string) ($target['path'] ?? '/'), '/');

    $current = wp_parse_url(home_url('/'));
    $current_host = is_array($current) ? strtolower((string) ($current['host'] ?? '')) : '';
    $current_scheme = is_array($current) ? strtolower((string) ($current['scheme'] ?? '')) : '';
    $current_port_raw = is_array($current) && isset($current['port']) ? (int) $current['port'] : null;
    $current_port = in_array([$current_scheme, $current_port_raw], [['http', 80], ['https', 443]], true)
        ? null
        : $current_port_raw;
    $current_path = is_array($current) ? '/' . ltrim((string) ($current['path'] ?? '/'), '/') : '/';
    $current_path = trailingslashit($current_path);

    foreach (fsr_etit_link_marker_get_old_urls() as $old_url) {
        $old = wp_parse_url($old_url);
        if (!is_array($old) || empty($old['host'])) {
            continue;
        }

        $old_host = strtolower((string) $old['host']);
        $old_scheme = strtolower((string) ($old['scheme'] ?? ''));
        $old_port_raw = isset($old['port']) ? (int) $old['port'] : null;
        $old_port = in_array([$old_scheme, $old_port_raw], [['http', 80], ['https', 443]], true)
            ? null
            : $old_port_raw;
        $old_path = trailingslashit('/' . ltrim((string) ($old['path'] ?? '/'), '/'));

        if ($target_host !== $old_host || $target_port !== $old_port) {
            continue;
        }

        // Do not classify the site's own complete base URL as legacy.
        if (
            $old_host === $current_host
            && $old_port === $current_port
            && $old_path === $current_path
        ) {
            continue;
        }

        if ($old_path === '/' || str_starts_with(trailingslashit($target_path), $old_path)) {
            return true;
        }
    }

    return false;
}

/**
 * Classifies one URL.
 */
function fsr_etit_link_marker_classify_url(string $url): array {
    $url = fsr_etit_link_marker_strip_fragment($url);
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

    if ($host === '' || $site_host === '') {
        return ['status' => 'ignore'];
    }

    if (fsr_etit_link_marker_is_old_url($url)) {
        return [
            'status' => 'old',
            'url'    => $url,
        ];
    }

    if ($host !== $site_host) {
        return ['status' => 'external'];
    }

    // Try to resolve actual WordPress content first. This also lets us inspect
    // pages without performing a network request against ourselves.
    $post_id = url_to_postid($url);

    if ($post_id > 0) {
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return [
                'status' => 'missing',
                'url'    => $url,
            ];
        }

        if (
            $post->post_type === 'page'
            && fsr_etit_link_marker_is_heading_only($post->post_content)
        ) {
            return [
                'status' => 'empty',
                'url'    => $url,
                'postId' => (int) $post->ID,
            ];
        }

        return [
            'status' => 'ok',
            'url'    => $url,
            'postId' => (int) $post->ID,
        ];
    }

    /*
     * url_to_postid() does not cover every valid WordPress URL
     * (archives, taxonomy pages, custom routes, etc.). For unresolved internal
     * URLs, use a short server-side request and cache the result.
     */
    $cache_key = 'fsr_lm_' . md5($url . '|' . implode('|', fsr_etit_link_marker_get_old_urls()));
    $cached = get_transient($cache_key);

    if (is_array($cached) && isset($cached['status'])) {
        return $cached;
    }

    // The host was validated above as this WordPress site's own host.
    // wp_remote_get() is intentional here: wp_safe_remote_get() rejects local/private
    // development hosts, which made 404 detection fail in environments such as Local.
    $response = wp_remote_get(
        $url,
        [
            'timeout'             => 4,
            'redirection'         => 3,
            'limit_response_size' => 16 * 1024,
            'headers'             => [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            ],
            'user-agent'          => 'FSR-ETIT-Link-Marker/' . FSR_ETIT_LINK_MARKER_VERSION,
        ]
    );

    if (is_wp_error($response)) {
        // Network errors are not automatically treated as 404s.
        $result = [
            'status' => 'unknown',
            'url'    => $url,
        ];
    } else {
        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            $result = [
                'status' => 'missing',
                'url'    => $url,
                'http'   => 404,
            ];
        } elseif ($code >= 200 && $code < 400) {
            $result = [
                'status' => 'ok',
                'url'    => $url,
                'http'   => $code,
            ];
        } else {
            $result = [
                'status' => 'unknown',
                'url'    => $url,
                'http'   => $code,
            ];
        }
    }

    set_transient($cache_key, $result, HOUR_IN_SECONDS);

    return $result;
}

/**
 * Returns true when the stored page content contains only headings/whitespace.
 */
function fsr_etit_link_marker_is_heading_only(string $content): bool {
    $content = trim($content);

    if ($content === '') {
        return true;
    }

    /*
     * Gutenberg block comments and shortcode comments should not make an
     * otherwise empty page count as content.
     */
    $content = preg_replace('/<!--\s*\/?wp:[\s\S]*?-->/i', '', $content);
    $content = trim((string) $content);

    if ($content === '') {
        return true;
    }

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $body = $dom->getElementsByTagName('body')->item(0);

            if (!$body) {
                $body = $dom;
            }

            $has_non_heading_content = false;

            $walk = static function ($node) use (&$walk, &$has_non_heading_content): void {
                if ($has_non_heading_content || !($node instanceof DOMNode)) {
                    return;
                }

                foreach ($node->childNodes as $child) {
                    if (
                        $child->nodeType === XML_TEXT_NODE
                        && trim((string) $child->nodeValue) !== ''
                    ) {
                        $has_non_heading_content = true;
                        return;
                    }

                    if ($child->nodeType !== XML_ELEMENT_NODE) {
                        continue;
                    }

                    $tag = strtolower((string) $child->nodeName);

                    if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                        continue;
                    }

                    /*
                     * Images, embeds, forms, tables, buttons, etc. are real
                     * content even if they contain little or no text.
                     */
                    if (in_array(
                        $tag,
                        ['img', 'video', 'audio', 'iframe', 'object', 'embed', 'table', 'form', 'button', 'input', 'canvas'],
                        true
                    )) {
                        $has_non_heading_content = true;
                        return;
                    }

                    $walk($child);

                    if ($has_non_heading_content) {
                        return;
                    }
                }
            };

            $walk($body);

            return !$has_non_heading_content;
        }
    }

    /*
     * Conservative fallback if DOMDocument is unavailable.
     */
    $without_headings = preg_replace(
        '/<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/i',
        '',
        $content
    );
    $text = trim(
        wp_strip_all_tags((string) $without_headings)
    );

    return $text === '';
}

/**
 * Visibility control. These defaults match the requested behavior:
 * - missing: everyone
 * - empty: everyone
 * - old: administrators only
 *
 * The future admin settings page can override these filters or replace the
 * source of the values with plugin options.
 */
function fsr_etit_link_marker_can_show(string $type): bool {
    $settings = function_exists('fsr_etit_link_marker_get_settings')
        ? fsr_etit_link_marker_get_settings()
        : [];

    $visibility = match ($type) {
        'old'     => (string) apply_filters('fsr_etit_link_marker_old_visibility', $settings['old_visibility'] ?? 'admin'),
        'missing' => (string) apply_filters('fsr_etit_link_marker_missing_visibility', $settings['missing_visibility'] ?? 'all'),
        'empty'   => (string) apply_filters('fsr_etit_link_marker_empty_visibility', $settings['empty_visibility'] ?? 'all'),
        default   => 'off',
    };

    if ($visibility === 'off') {
        return false;
    }

    if ($visibility === 'admin') {
        return current_user_can('manage_options');
    }

    return true;
}
