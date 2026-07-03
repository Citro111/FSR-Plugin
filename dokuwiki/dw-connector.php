<?php
if (!defined('ABSPATH')) exit;

// Hooks für DokuWiki
add_action('init', 'fsr_dw_rewrite_rules');
add_filter('query_vars', 'fsr_dw_query_vars');
add_filter('template_include', 'fsr_dw_template_router');   
add_action('init', 'fsr_dw_asset_proxy');

function fsr_dw_get_settings() {
    return wp_parse_args(get_option('dw_bridge_settings', []), [
        'base_url' => 'https://fsr-etit.de',
        'cache_time' => 100,
        'start_page' => 'aktuelles'
    ]);
}

function fsr_dw_render_admin_fields() {
    $s = fsr_dw_get_settings();
    echo '<h3>DokuWiki Bridge Einstellungen</h3><table class="form-table">';
    echo "<tr><th>DokuWiki URL</th><td><input style='width:400px' name='dw_bridge_settings[base_url]' value='".esc_attr($s['base_url'])."'></td></tr>";
    echo "<tr><th>Startseite</th><td><input name='dw_bridge_settings[start_page]' value='".esc_attr($s['start_page'])."'></td></tr>";
    echo "<tr><th>Cache (Sekunden)</th><td><input type='number' name='dw_bridge_settings[cache_time]' value='".esc_attr($s['cache_time'])."'></td></tr>";
    echo '</table>';
}

// RESTLICHE INTERNE DOKUWIKI CONNECTOR LOGIK (Aus dw-bridge 3.3 gekapselt)[cite: 1]
function fsr_dw_rewrite_rules() {
    add_rewrite_rule('^wiki/?$', 'index.php?dw_page=start', 'top');
    add_rewrite_rule('^wiki/(.+)/?$', 'index.php?dw_page=$matches[1]', 'top');
}
function fsr_dw_query_vars($vars) { $vars[] = 'dw_page'; return $vars; }
function fsr_dw_template_router($template) { $page = get_query_var('dw_page'); if ($page !== '' && $page !== null) { return FSR_PLUGIN_DIR . 'global/template.php'; } return $template; }

function fsr_dw_fetch($page) {
    $s = fsr_dw_get_settings(); if (!$page) $page = $s['start_page'];
    $cache_key = 'dw_' . md5($page); $cached = get_transient($cache_key); if ($cached) return $cached;
    $url = rtrim($s['base_url'], '/') . '/doku.php?id=' . urlencode($page) . '&do=export_xhtmlbody';
    $res = wp_remote_get($url, ['timeout' => 12]); if (is_wp_error($res)) return false;
    $html = wp_remote_retrieve_body($res); if (!$html) return false;
    $html = fsr_dw_transform($html); set_transient($cache_key, $html, intval($s['cache_time'])); return $html;
}

function fsr_dw_search($query) {
    $s = fsr_dw_get_settings();
    $url =  rtrim($s['base_url'], '/') .'/aktuelles?do=search&id=' .
            urlencode('protokolle:sitzungsprotokolle') . '&sf=1&q=' .
            urlencode(trim($query) . ' @protokolle') . '&srt=mtime';
    $res = wp_remote_get($url, ['timeout' => 12, 'user-agent' => 'Mozilla/5.0']);
    if (is_wp_error($res)) return '';
    $html = wp_remote_retrieve_body($res);
    if (!$html) return '';
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query("//div[contains(@class,'search_fullpage_result')]") as $node) {

        $link = $xpath->query(".//h3/a", $node)->item(0);

        if (!$link) {
            continue;
        }

        $title = trim($link->textContent);

        $href = $link->getAttribute('href');

        parse_str(parse_url($href, PHP_URL_QUERY), $query);

        $page = $query['id'] ?? '';

        $snippetNode = $xpath->query(
            ".//*[contains(@class,'search_snippet') or contains(@class,'search_excerpt')]",
            $node
        )->item(0);

        $excerpt = $snippetNode
            ? trim($snippetNode->textContent)
            : '';

        $virtual_posts[] = fsr_create_virtual_search_post(
            $title,
            $excerpt,
            $excerpt,
            home_url('/wiki/' . $page),
            '',
            'page'
        );
    }
    return $virtual_posts;
}

function fsr_dw_transform($html) {
    libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); $s = fsr_dw_get_settings(); $base_url = rtrim($s['base_url'], '/');
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href')); if (!$href) continue; $clean_href = strtok($href, '?');
        $open_in_new_tab = false;
        if (!str_starts_with($href, 'http') && !str_starts_with($href, '#') && !str_starts_with($href, '/wiki/')) {
            if (!str_contains($href, '/') || str_contains($href, ':')) { $clean_href = ltrim($clean_href, ':'); $target_page = ltrim($clean_href, '/'); $a->setAttribute('href', home_url('/wiki/' . $target_page)); $open_in_new_tab = fsr_dw_should_open_in_new_tab($target_page); if ($open_in_new_tab) { $a->setAttribute('target', '_blank'); $a->setAttribute('rel', 'noopener noreferrer'); } continue; }
        }
        if (str_contains($href, 'doku.php?id=')) { parse_str(parse_url($href, PHP_URL_QUERY), $query); if (!empty($query['id'])) { $page = ltrim($query['id'], ':'); $a->setAttribute('href', home_url('/wiki/' . ltrim($page, '/'))); $open_in_new_tab = fsr_dw_should_open_in_new_tab($page); if ($open_in_new_tab) { $a->setAttribute('target', '_blank'); $a->setAttribute('rel', 'noopener noreferrer'); } } }
        if (str_starts_with($href, '/wiki/')) {
            $page = ltrim(substr($clean_href, strlen('/wiki/')), '/');
            if (fsr_dw_should_open_in_new_tab($page)) {
                $a->setAttribute('target', '_blank');
                $a->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }
    foreach ($dom->getElementsByTagName('img') as $img) {
        $src = trim($img->getAttribute('src')); if (!$src) continue; $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
        if (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) { $src = $base_url . '/' . ltrim($src, '/'); }
        $img->setAttribute('src', home_url('/?dw_asset=' . urlencode($src)));
        $current_class = $img->getAttribute('class'); $img->setAttribute('class', $current_class . ' dw-attached-image'); $img->setAttribute('loading', 'lazy');
    }
    return $dom->saveHTML();
}

function fsr_dw_should_open_in_new_tab($page) {
    $page = strtolower(trim((string) $page));
    if ($page === '') {
        return false;
    }

    return (strpos($page, 'nonpublic') !== false || strpos($page, 'intern') !== false);
}

function fsr_dw_asset_proxy() {
    if (!isset($_GET['dw_asset'])) return; $url = rawurldecode($_GET['dw_asset']); $s = fsr_dw_get_settings(); $base = rtrim($s['base_url'], '/');
    if (strpos($url, $base) !== 0) { status_header(403); exit; }
    $res = wp_remote_get($url, [ 'timeout' => 15, 'user-agent' => 'Mozilla/5.0' ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) { status_header(404); exit; }
    $content_type = wp_remote_retrieve_header($res, 'content-type'); if ($content_type) header('Content-Type: ' . $content_type);
    echo wp_remote_retrieve_body($res); exit;
}