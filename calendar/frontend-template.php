<?php
if (!defined('ABSPATH')) {
    exit;
}
add_shortcode('fsr_events', 'fsr_render_events');
function fsr_render_events($atts) {
    $atts = shortcode_atts(
        [
            'count' => 5,
            'category' => ''
        ],
        $atts
    );
    global $wp;
    $count = intval($atts['count']);
    $category = sanitize_title($atts['category']);
    $calendar_url = get_option(FSR_CALENDAR_URL);
    if (!$calendar_url) {
        return '<p>Kein Kalender hinterlegt.</p>';
    }
    $events = fsr_get_calendar_events($calendar_url);
    error_log('CALENDAR: Total events fetched: ' . count($events));
    $events = array_filter(
        $events,
        function($event){
            error_log('CALENDAR: Checking event: ' . $event['title'] . ' | timestamp: ' . $event['timestamp'] . '-' . current_time('timestamp') . ' | Status: ' . ($event['timestamp'] >= current_time('timestamp') ? 'active' : 'inactive'));
            return $event['timestamp'] >= current_time('timestamp');
        }
    );
    error_log('CALENDAR: Active events: ' . count($events));
    $events = array_values($events);
    error_log('CALENDAR: Active events after reindexing: ' . count($events));
    foreach($events as $event){
        error_log(
            $event['title'] . ' | ' .
            date('d.m.Y H:i', $event['timestamp']) . ' | ' .
            $event['type']
        );
    }
    if ($category) {
        $events = array_filter(
            $events,
            function($event) use ($category) {
                return $event['type'] === $category;
            }
        );
    }
    error_log('CALENDAR: Nach Kategorie gefiltert: ' . count($events));
    if (!$events) {
        return '<p>Keine Veranstaltungen gefunden.</p>';
    }
    /*
     * Sortieren
     */
    usort(
        $events,
        function($a,$b){
            return $a['timestamp'] <=> $b['timestamp'];
        }
    );
    /*
     * NerdBar reduzieren:
     * nur den ersten Termin anzeigen
     */
    $result = [];
    $nerdbar_found = false;
    error_log("Vor NerdBar Filter: " . count($events));
    foreach($events as $event) {
        if(!$category && $event['type'] === 'nerdbar') {
            if($nerdbar_found) {
                error_log('CALENDAR: Skipping nerdbar event: ' . print_r($event, true));
                continue;
            }
            $nerdbar_found = true;
        }
        $result[] = $event;
        if(count($result) >= $count){
            error_log('CALENDAR: Reached count limit of ' . $count . ', stopping event processing.');
            break;
        }
    }
    error_log("Nach NerdBar Filter: " . count($result));
    ob_start();
    ?>
    <div class="fsr-events">
        <?php foreach($result as $event): 
            $post = fsr_create_virtual_post(
                $event['title'],
                $event['description'],
                $event['description'],
                $event['url'],
                date('Y-m-d H:i:s', $event['timestamp']),
                'event'
            );
            setup_postdata($post);
        ?>
            <article class="fsr-event">
                <h5>
                    <?php if($post->url !== ''): ?>
                        <a href="<?php echo esc_url($post->url); ?>">
                            <?php the_title(); ?>
                        </a>
                    <?php else: ?>
                        <?php the_title(); ?>
                    <?php endif; ?>
                </h5>
                <div>
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?php wp_reset_postdata(); ?>
    </div>
    <?php
    return ob_get_clean();
}