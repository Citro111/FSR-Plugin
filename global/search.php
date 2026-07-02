<?php
if(!defined('ABSPATH')) exit;
echo '<div style="background:red;color:white;padding:10px;">search.php geladen</div>';


add_filter('the_posts', 'fsr_ensure_search_loop_runs', 10, 2);
add_action('loop_end', 'fsr_append_search_results');
add_filter('post_class', 'fsr_mark_placeholder_post', 10, 3);

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
     echo '<div style="background:#ffeb3b;padding:10px;margin:15px 0;font-weight:bold;">
        DEBUG: fsr_append_search_results() wurde aufgerufen.
    </div>';

    if ($done || is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return;
    }
    echo '<div style="background:#8bc34a;padding:10px;margin:15px 0;font-weight:bold;">
        DEBUG: Alle Bedingungen erfüllt.
    </div>';

    $search = trim(get_search_query(false));

    if ($search === '') {
        return;
    }
    echo '<div style="background:#ffeb3b;padding:10px;margin:15px 0;font-weight:bold;">
        DEBUG: Suche nach "' . esc_html($search) . '".
    </div>';

    if ($members = fsr_membercards_search($search)) {
        fsr_search_result('Mitglieder', $members, '', 'membercards-search-results-content');
        echo '<div class="membercards-search-results-content">';
        echo '<h3>Mitglieder</h3>';
        echo $members;
        echo '</div>';
    }

    if ($hours = fsr_office_hours_search($search)) {
        fsr_search_result('Sprechstunden', $hours, '', 'office-hours-search-results-content');
        echo '<div class="office-hours-search-results-content">';
        echo '<h3>Sprechstunden</h3>';
        echo $hours;
        echo '</div>';
    }

    if ($dw = fsr_dw_search($search)) {
        fsr_search_result('Protokolle', $dw, '', 'dw-search-results-content');
    }

    $done = true;
}

function fsr_mark_placeholder_post($classes, $class, $post_id) {
    if ($post_id === -1) {
        $classes[] = 'search-placeholder';
    }
    return $classes;
}

function fsr_search_result($title, array $lines = [], $url = '', $class = []) {
    /**
     * Erzeugt einen einheitlichen Suchtreffer.
     *
     * @param string       $title   Überschrift des Treffers.
     * @param array        $lines   Zusätzliche Zeilen unter dem Titel.
     * @param string|null  $url     Optionaler Link.
     * @param string       $class   Zusätzliche CSS-Klasse(n).
     *
     * @return string
     */
    $classes = ['fsr-search-result'];
    if (!empty($class)) {
        $classes[] = sanitize_html_class($class);
    }
    $html = '<div class="' . esc_attr(implode(' ', $classes)) . '">';
    if (!empty($url)) {
        $html .= sprintf(
            '<a href="%s"><strong>%s</strong></a>',
            esc_url($url),
            esc_html($title)
        );
    } else {
        $html .= '<strong>' . esc_html($title) . '</strong>';
    }
    foreach ($lines as $line) {
        if ($line === '' || $line === null) {
            continue;
        }
        $html .= '<br>' . esc_html($line);
    }
    $html .= '</div>';
    return $html;
}