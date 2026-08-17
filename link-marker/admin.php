<?php
/**
 * FSR ET/IT Link Marker - Admin integration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_fsr_etit_link_marker_save_browser_results', 'fsr_etit_link_marker_save_browser_results');

/**
 * Normalizes one legacy base URL.
 * A bare host such as "fsr-etit.de" is accepted and stored as HTTPS URL.
 */
function fsr_etit_link_marker_normalize_old_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $host = strtolower((string) $parts['host']);
    $port_number = isset($parts['port']) ? absint($parts['port']) : 0;
    $is_default_port = ($scheme === 'https' && $port_number === 443)
        || ($scheme === 'http' && $port_number === 80);
    $port = $port_number > 0 && !$is_default_port ? ':' . $port_number : '';
    $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
    $path = trailingslashit($path);

    return esc_url_raw($scheme . '://' . $host . $port . $path, ['http', 'https']);
}

/**
 * Sanitizes the textarea containing one legacy base URL per line.
 */
function fsr_etit_link_marker_sanitize_old_urls($value): array {
    if (is_array($value)) {
        $lines = $value;
    } else {
        $lines = preg_split('/\R+/', (string) $value) ?: [];
    }

    $urls = [];
    foreach ($lines as $line) {
        $url = fsr_etit_link_marker_normalize_old_url((string) $line);
        if ($url !== '') {
            $urls[] = $url;
        }
    }

    return array_values(array_unique($urls));
}

function fsr_etit_link_marker_get_settings(): array {
    $defaults = [
        'old_visibility' => 'admin',
        'missing_visibility' => 'all',
        'empty_visibility' => 'all',
        'old_urls' => ['https://fsr-etit.de/'],
    ];

    $saved = get_option('fsr_etit_link_marker_settings', []);
    $saved = is_array($saved) ? $saved : [];

    // Compatibility with the first Link-Marker build which had no configurable URL.
    if (!array_key_exists('old_urls', $saved)) {
        $saved['old_urls'] = $defaults['old_urls'];
    }

    $settings = wp_parse_args($saved, $defaults);
    $settings['old_urls'] = fsr_etit_link_marker_sanitize_old_urls($settings['old_urls']);

    return $settings;
}

function fsr_etit_link_marker_get_old_urls(): array {
    $settings = fsr_etit_link_marker_get_settings();
    $urls = $settings['old_urls'] ?? [];

    /**
     * Allows installations to add legacy URL bases without changing the saved settings.
     */
    $urls = apply_filters('fsr_etit_link_marker_old_urls', $urls);

    return fsr_etit_link_marker_sanitize_old_urls($urls);
}

/**
 * Render the admin page. This is public on purpose so the existing plugin
 * admin router can call it directly.
 */
function fsr_etit_link_marker_render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    if (isset($_POST['fsr_etit_link_marker_save'])) {
        check_admin_referer('fsr_etit_link_marker_settings');

        $allowed = ['all', 'admin', 'off'];

        $old_visibility = sanitize_key(wp_unslash($_POST['old_visibility'] ?? 'admin'));
        $missing_visibility = sanitize_key(wp_unslash($_POST['missing_visibility'] ?? 'all'));
        $empty_visibility = sanitize_key(wp_unslash($_POST['empty_visibility'] ?? 'all'));

        if (!in_array($old_visibility, $allowed, true)) {
            $old_visibility = 'admin';
        }
        if (!in_array($missing_visibility, $allowed, true)) {
            $missing_visibility = 'all';
        }
        if (!in_array($empty_visibility, $allowed, true)) {
            $empty_visibility = 'all';
        }

        $old_urls = fsr_etit_link_marker_sanitize_old_urls(
            wp_unslash($_POST['old_urls'] ?? '')
        );

        update_option('fsr_etit_link_marker_settings', [
            'old_visibility' => $old_visibility,
            'missing_visibility' => $missing_visibility,
            'empty_visibility' => $empty_visibility,
            'old_urls' => $old_urls,
        ], false);

        echo '<div class="notice notice-success is-dismissible"><p>Link-Marker-Einstellungen gespeichert.</p></div>';
    }

    if (isset($_POST['fsr_etit_link_marker_scan'])) {
        check_admin_referer('fsr_etit_link_marker_scan');

        $report = fsr_etit_link_marker_scan_site();
        update_option('fsr_etit_link_marker_report', $report, false);

        echo '<div class="notice notice-success is-dismissible"><p>Link-Scan abgeschlossen.</p></div>';
    }

    $settings = fsr_etit_link_marker_get_settings();
    $report = get_option('fsr_etit_link_marker_report', []);
    $report = is_array($report) ? $report : [];
    ?>
    <div class="fsr-link-marker-admin">
        <h2>Link-Marker</h2>
        <p>Markiert problematische Links im Frontend und erstellt auf Wunsch einen gruppierten Bericht über die veröffentlichten Inhalte.</p>

        <h3>Einstellungen</h3>
        <form method="post">
            <?php wp_nonce_field('fsr_etit_link_marker_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="fsr-etit-old-urls">Alte Website-URLs</label></th>
                    <td>
                        <textarea id="fsr-etit-old-urls" name="old_urls" rows="4" class="large-text code" placeholder="https://fsr-etit.de/\nhttps://alte-domain.example/unterpfad/"><?php echo esc_textarea(implode("\n", $settings['old_urls'])); ?></textarea>
                        <p class="description">Eine Basis-URL pro Zeile. Es können komplette alte Domains oder nur alte Unterpfade angegeben werden. <code>fsr-etit.de</code> ohne Protokoll ist ebenfalls erlaubt.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Alte Links</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('old_visibility', $settings['old_visibility']); ?>
                        <p class="description">Links, die zu einer der oben eingetragenen alten Website-URLs gehören.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">404 / fehlende Ziele</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('missing_visibility', $settings['missing_visibility']); ?>
                        <p class="description">Interne Links, deren Ziel als HTTP 404 erkannt wird.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Leere Seiten</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('empty_visibility', $settings['empty_visibility']); ?>
                        <p class="description">WordPress-Seiten, die praktisch nur aus Überschriften/Leerraum bestehen.</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="fsr_etit_link_marker_save" class="button button-primary">
                    Einstellungen speichern
                </button>
            </p>
        </form>

        <hr>

        <h3>Link-Bericht</h3>
        <p>Der Scan durchsucht die gespeicherten Inhalte aller veröffentlichten öffentlichen Beitragstypen. WordPress-Ziele werden direkt aus der Datenbank geprüft. Interne URLs, die WordPress nicht eindeutig auflösen kann, werden ohne blockierenden Server-Loopback gesammelt und können anschließend direkt im Browser geprüft werden.</p>
        <p class="description">Hinweis: Links, die ausschließlich zur Laufzeit durch ein Theme, JavaScript oder ein dynamisches Plugin erzeugt werden, können in diesem Inhalts-Scan fehlen.</p>

        <form method="post" style="margin:16px 0 20px;">
            <?php wp_nonce_field('fsr_etit_link_marker_scan'); ?>
            <button type="submit" name="fsr_etit_link_marker_scan" class="button button-secondary">
                Website jetzt scannen
            </button>
        </form>

        <?php fsr_etit_link_marker_render_report($report); ?>
    </div>
    <?php
}

function fsr_etit_link_marker_visibility_select(string $name, string $value): void {
    $options = [
        'all' => 'Alle Besucher',
        'admin' => 'Nur Administratoren',
        'off' => 'Deaktiviert',
    ];

    echo '<select name="' . esc_attr($name) . '">';
    foreach ($options as $option_value => $label) {
        printf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr($option_value),
            selected($value, $option_value, false),
            esc_html($label)
        );
    }
    echo '</select>';
}

/**
 * Resolves one href against the source permalink.
 */
function fsr_etit_link_marker_resolve_href(string $href, string $source_url): string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#')) {
        return '';
    }

    if (preg_match('#^(?:mailto|tel|javascript|data):#i', $href)) {
        return '';
    }

    if (preg_match('#^https?://#i', $href)) {
        return esc_url_raw($href, ['http', 'https']);
    }

    $source = wp_parse_url($source_url);
    if (!is_array($source) || empty($source['host'])) {
        return '';
    }

    $scheme = strtolower((string) ($source['scheme'] ?? 'https'));
    $host = (string) $source['host'];
    $port = isset($source['port']) ? ':' . absint($source['port']) : '';

    if (str_starts_with($href, '//')) {
        return esc_url_raw($scheme . ':' . $href, ['http', 'https']);
    }

    $fragment_pos = strpos($href, '#');
    if ($fragment_pos !== false) {
        $href = substr($href, 0, $fragment_pos);
    }

    $query = '';
    $query_pos = strpos($href, '?');
    if ($query_pos !== false) {
        $query = substr($href, $query_pos);
        $href = substr($href, 0, $query_pos);
    }

    if ($href === '') {
        $path = (string) ($source['path'] ?? '/');
    } elseif (str_starts_with($href, '/')) {
        $path = $href;
    } else {
        $source_path = (string) ($source['path'] ?? '/');
        $base_path = str_ends_with($source_path, '/')
            ? $source_path
            : trailingslashit(dirname($source_path));
        $path = $base_path . $href;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $path = '/' . implode('/', $segments);
    if ($href !== '' && str_ends_with($href, '/') && $path !== '/') {
        $path .= '/';
    }

    return esc_url_raw($scheme . '://' . $host . $port . $path . $query, ['http', 'https']);
}

/**
 * Extracts links from one stored WordPress content string.
 */
function fsr_etit_link_marker_extract_links(string $content, string $source_url): array {
    if ($content === '') {
        return [];
    }

    $links = [];

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="fsr-link-marker-root">' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            foreach ($dom->getElementsByTagName('a') as $anchor) {
                if (!$anchor->hasAttribute('href')) {
                    continue;
                }

                $url = fsr_etit_link_marker_resolve_href($anchor->getAttribute('href'), $source_url);
                if ($url === '') {
                    continue;
                }

                $text = trim((string) preg_replace('/\s+/u', ' ', (string) $anchor->textContent));
                $links[] = [
                    'url' => $url,
                    'text' => $text,
                ];
            }

            return $links;
        }
    }

    if (preg_match_all('/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $url = fsr_etit_link_marker_resolve_href((string) $match[2], $source_url);
            if ($url === '') {
                continue;
            }
            $links[] = [
                'url' => $url,
                'text' => trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $match[3]))),
            ];
        }
    }

    return $links;
}

/**
 * Scans published public content and groups problematic links by classification.
 */
function fsr_etit_link_marker_scan_site(): array {
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    $public_post_types = get_post_types(['public' => true], 'names');
    unset($public_post_types['attachment']);

    $post_types = array_values($public_post_types);
    $post_types = apply_filters('fsr_etit_link_marker_scan_post_types', $post_types);
    $post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types)));

    $query = new WP_Query([
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);

    $groups = [
        'missing' => [],
        'empty' => [],
        'old' => [],
        'unknown' => [],
    ];
    $classification_cache = [];
    $seen_hits = [];
    $links_found = 0;

    foreach ($query->posts as $post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            continue;
        }

        $source_url = get_permalink($post);
        if (!$source_url) {
            continue;
        }

        $links = fsr_etit_link_marker_extract_links((string) $post->post_content, $source_url);
        $links_found += count($links);

        foreach ($links as $link) {
            $target_url = (string) ($link['url'] ?? '');
            if ($target_url === '') {
                continue;
            }

            $classification_key = fsr_etit_link_marker_strip_fragment($target_url);
            if (!isset($classification_cache[$classification_key])) {
                $classification_cache[$classification_key] = fsr_etit_link_marker_classify_url($classification_key);
            }

            $status = $classification_cache[$classification_key];
            $group = (string) ($status['status'] ?? '');
            if (!array_key_exists($group, $groups)) {
                continue;
            }

            $dedupe_key = $group . '|' . absint($post->ID) . '|' . $classification_key . '|' . (string) ($link['text'] ?? '');
            if (isset($seen_hits[$dedupe_key])) {
                continue;
            }
            $seen_hits[$dedupe_key] = true;

            $target_post_id = absint($status['postId'] ?? 0);
            $groups[$group][] = [
                'source_id' => (int) $post->ID,
                'source_title' => get_the_title($post) ?: '(ohne Titel)',
                'source_type' => (string) $post->post_type,
                'source_url' => esc_url_raw($source_url),
                'source_edit_url' => get_edit_post_link($post->ID, 'raw') ?: '',
                'link_text' => (string) ($link['text'] ?? ''),
                'target_url' => $target_url,
                'target_post_id' => $target_post_id,
                'target_edit_url' => $target_post_id ? (get_edit_post_link($target_post_id, 'raw') ?: '') : '',
                'http' => isset($status['http']) ? (int) $status['http'] : null,
                'reason' => sanitize_key((string) ($status['reason'] ?? '')),
            ];
        }
    }

    foreach ($groups as &$items) {
        usort($items, static function (array $a, array $b): int {
            $source_compare = strcasecmp((string) $a['source_title'], (string) $b['source_title']);
            if ($source_compare !== 0) {
                return $source_compare;
            }
            return strcasecmp((string) $a['target_url'], (string) $b['target_url']);
        });
    }
    unset($items);

    return [
        'generated_at' => current_time('mysql'),
        'pages_scanned' => count($query->posts),
        'links_found' => $links_found,
        'unique_targets_checked' => count($classification_cache),
        'groups' => $groups,
    ];
}

function fsr_etit_link_marker_report_source_count(array $items): int {
    $ids = [];
    foreach ($items as $item) {
        $id = absint($item['source_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return count($ids);
}

function fsr_etit_link_marker_render_report(array $report): void {
    if (empty($report['generated_at']) || !isset($report['groups']) || !is_array($report['groups'])) {
        echo '<p><em>Noch kein Bericht vorhanden. Starte oben einen Scan.</em></p>';
        return;
    }

    $groups = wp_parse_args($report['groups'], [
        'missing' => [],
        'empty' => [],
        'old' => [],
        'unknown' => [],
    ]);

    $missing = is_array($groups['missing']) ? $groups['missing'] : [];
    $empty = is_array($groups['empty']) ? $groups['empty'] : [];
    $old = is_array($groups['old']) ? $groups['old'] : [];
    $unknown = is_array($groups['unknown']) ? $groups['unknown'] : [];
    ?>
    <p>
        <strong>Letzter Scan:</strong> <?php echo esc_html((string) $report['generated_at']); ?> ·
        <?php echo esc_html(number_format_i18n(absint($report['pages_scanned'] ?? 0))); ?> Inhalte ·
        <?php echo esc_html(number_format_i18n(absint($report['links_found'] ?? 0))); ?> Links ·
        <?php echo esc_html(number_format_i18n(absint($report['unique_targets_checked'] ?? 0))); ?> eindeutige Ziele analysiert
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;max-width:900px;margin:16px 0 24px;">
        <?php fsr_etit_link_marker_render_summary_card('404-Links', $missing); ?>
        <?php fsr_etit_link_marker_render_summary_card('Leere Zielseiten', $empty); ?>
        <?php fsr_etit_link_marker_render_summary_card('Alte Links', $old); ?>
        <?php if (!empty($unknown)) { fsr_etit_link_marker_render_summary_card('Im Browser prüfen', $unknown); } ?>
    </div>

    <?php
    fsr_etit_link_marker_render_report_group('404 / fehlende Ziele', $missing, 'Keine 404-Links gefunden.');
    fsr_etit_link_marker_render_report_group('Links auf leere Seiten', $empty, 'Keine Links auf leere Seiten gefunden.');
    fsr_etit_link_marker_render_report_group('Links auf alte Website-URLs', $old, 'Keine alten Links gefunden.');

    if (!empty($unknown)) {
        fsr_etit_link_marker_render_browser_verifier($unknown);
        fsr_etit_link_marker_render_report_group(
            'Noch nicht serverseitig prüfbare interne Links',
            $unknown,
            'Keine ungeprüften internen Links vorhanden.'
        );
    }
}



/**
 * Saves HTTP results gathered by the administrator's browser and merges them
 * into the stored report. This avoids server-side loopback requests.
 */
function fsr_etit_link_marker_save_browser_results(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);
    }

    check_ajax_referer('fsr_etit_link_marker_browser_verify', 'nonce');

    $raw = isset($_POST['results'])
        ? fsr_etit_scalar_string(wp_unslash($_POST['results']))
        : '';
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        wp_send_json_error(['message' => 'Ungültige Prüfergebnisse.'], 400);
    }

    $verified = [];
    foreach (array_slice($decoded, 0, 500) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $url = esc_url_raw((string) ($item['url'] ?? ''), ['http', 'https']);
        $http = absint($item['http'] ?? 0);
        if ($url === '' || $http < 100 || $http > 599) {
            continue;
        }
        $verified[fsr_etit_link_marker_strip_fragment($url)] = $http;
    }

    $report = get_option('fsr_etit_link_marker_report', []);
    if (!is_array($report) || empty($report['groups']) || !is_array($report['groups'])) {
        wp_send_json_error(['message' => 'Kein gespeicherter Bericht vorhanden.'], 409);
    }

    $groups = wp_parse_args($report['groups'], [
        'missing' => [],
        'empty' => [],
        'old' => [],
        'unknown' => [],
    ]);

    $remaining_unknown = [];
    $known_missing_keys = [];
    foreach ((array) $groups['missing'] as $item) {
        $known_missing_keys[(int) ($item['source_id'] ?? 0) . '|' . (string) ($item['target_url'] ?? '') . '|' . (string) ($item['link_text'] ?? '')] = true;
    }

    foreach ((array) $groups['unknown'] as $item) {
        $target = fsr_etit_link_marker_strip_fragment((string) ($item['target_url'] ?? ''));
        $http = $verified[$target] ?? 0;

        if ($http === 404) {
            $item['http'] = 404;
            $item['reason'] = 'browser-verified';
            $key = (int) ($item['source_id'] ?? 0) . '|' . (string) ($item['target_url'] ?? '') . '|' . (string) ($item['link_text'] ?? '');
            if (!isset($known_missing_keys[$key])) {
                $groups['missing'][] = $item;
                $known_missing_keys[$key] = true;
            }
            continue;
        }

        if ($http >= 200 && $http < 400) {
            // A valid browser response needs no entry in the problem report.
            continue;
        }

        if ($http > 0) {
            $item['http'] = $http;
            $item['reason'] = 'browser-http-' . $http;
        }
        $remaining_unknown[] = $item;
    }

    $groups['unknown'] = $remaining_unknown;
    $report['groups'] = $groups;
    $report['browser_verified_at'] = current_time('mysql');
    update_option('fsr_etit_link_marker_report', $report, false);

    wp_send_json_success([
        'message' => 'Browser-Prüfung gespeichert.',
        'remaining_unknown' => count($remaining_unknown),
    ]);
}

function fsr_etit_link_marker_render_browser_verifier(array $unknown): void {
    $urls = [];
    foreach ($unknown as $item) {
        $url = esc_url_raw((string) ($item['target_url'] ?? ''), ['http', 'https']);
        if ($url !== '') {
            $urls[$url] = true;
        }
    }

    $urls = array_keys($urls);
    if (empty($urls)) {
        return;
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('fsr_etit_link_marker_browser_verify');
    ?>
    <div class="notice notice-info inline" style="margin:20px 0;padding:12px 16px;">
        <p><strong><?php echo esc_html(number_format_i18n(count($urls))); ?> interne Ziele konnten ohne Loopback-HTTP nicht abschließend geprüft werden.</strong></p>
        <p>Das ist besonders in Local-Entwicklungsumgebungen normal. Du kannst diese Ziele direkt aus deinem Browser prüfen; bestätigte 404s werden anschließend in die 404-Gruppe verschoben.</p>
        <p>
            <button type="button" class="button button-secondary" id="fsr-etit-browser-link-check">Ungeprüfte URLs im Browser prüfen</button>
            <span id="fsr-etit-browser-link-check-status" style="margin-left:8px;"></span>
        </p>
    </div>
    <script>
    (() => {
        const button = document.getElementById('fsr-etit-browser-link-check');
        const status = document.getElementById('fsr-etit-browser-link-check-status');
        if (!button || !status) return;

        const urls = <?php echo wp_json_encode($urls); ?>;
        const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
        const nonce = <?php echo wp_json_encode($nonce); ?>;

        const checkOne = async (url) => {
            try {
                let response = await fetch(url, {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    redirect: 'follow',
                    cache: 'no-store'
                });
                if (response.status === 405 || response.status === 501) {
                    response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        redirect: 'follow',
                        cache: 'no-store'
                    });
                }
                return { url, http: response.status };
            } catch (error) {
                return { url, http: 0 };
            }
        };

        const runPool = async (items, concurrency = 4) => {
            const results = [];
            let index = 0;
            const worker = async () => {
                while (index < items.length) {
                    const current = items[index++];
                    const result = await checkOne(current);
                    results.push(result);
                    status.textContent = `${results.length}/${items.length} geprüft …`;
                }
            };
            await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, worker));
            return results;
        };

        button.addEventListener('click', async () => {
            button.disabled = true;
            status.textContent = `0/${urls.length} geprüft …`;

            const results = await runPool(urls);
            const form = new FormData();
            form.append('action', 'fsr_etit_link_marker_save_browser_results');
            form.append('nonce', nonce);
            form.append('results', JSON.stringify(results));

            try {
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: form
                });
                const data = await response.json();
                if (!response.ok || !data || !data.success) {
                    throw new Error('Speichern fehlgeschlagen');
                }
                status.textContent = 'Fertig – Bericht wird aktualisiert …';
                window.location.reload();
            } catch (error) {
                status.textContent = 'Prüfung abgeschlossen, Ergebnisse konnten aber nicht gespeichert werden.';
                button.disabled = false;
            }
        });
    })();
    </script>
    <?php
}

function fsr_etit_link_marker_render_summary_card(string $title, array $items): void {
    $sources = fsr_etit_link_marker_report_source_count($items);
    ?>
    <div class="card" style="max-width:none;margin:0;padding:14px 16px;">
        <strong style="display:block;font-size:14px;"><?php echo esc_html($title); ?></strong>
        <span style="display:block;font-size:26px;line-height:1.25;margin-top:4px;"><?php echo esc_html(number_format_i18n(count($items))); ?></span>
        <span class="description"><?php echo esc_html(number_format_i18n($sources)); ?> betroffene Inhalte</span>
    </div>
    <?php
}

function fsr_etit_link_marker_render_report_group(string $title, array $items, string $empty_message): void {
    ?>
    <h4 style="font-size:1.15em;margin-top:28px;"><?php echo esc_html($title); ?> <span class="count">(<?php echo esc_html(number_format_i18n(count($items))); ?>)</span></h4>
    <?php
    if (empty($items)) {
        echo '<p>' . esc_html($empty_message) . '</p>';
        return;
    }
    ?>
    <table class="widefat striped" style="table-layout:fixed;">
        <thead>
            <tr>
                <th style="width:23%;">Quellseite</th>
                <th style="width:18%;">Linktext</th>
                <th>Ziel</th>
                <th style="width:18%;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item) : ?>
            <tr>
                <td>
                    <strong><?php echo esc_html((string) ($item['source_title'] ?? '(ohne Titel)')); ?></strong><br>
                    <span class="description"><?php echo esc_html((string) ($item['source_type'] ?? '')); ?> · ID <?php echo esc_html((string) absint($item['source_id'] ?? 0)); ?></span>
                </td>
                <td><?php echo ($item['link_text'] ?? '') !== '' ? esc_html((string) $item['link_text']) : '<em>(kein Linktext)</em>'; ?></td>
                <td style="overflow-wrap:anywhere;"><code><?php echo esc_html((string) ($item['target_url'] ?? '')); ?></code></td>
                <td>
                    <?php if (!empty($item['source_url'])) : ?>
                        <a href="<?php echo esc_url((string) $item['source_url']); ?>" target="_blank" rel="noopener">Ansehen</a>
                    <?php endif; ?>
                    <?php if (!empty($item['source_edit_url'])) : ?>
                        · <a href="<?php echo esc_url((string) $item['source_edit_url']); ?>">Bearbeiten</a>
                    <?php endif; ?>
                    <?php if (!empty($item['target_edit_url'])) : ?>
                        <br><a href="<?php echo esc_url((string) $item['target_edit_url']); ?>">Ziel bearbeiten</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
