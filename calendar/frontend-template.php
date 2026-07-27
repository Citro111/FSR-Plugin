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
            return $event['timestamp'] >= time();
        }
    );
    error_log('CALENDAR: Active events: ' . count($events));
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
        error_log('CALENDAR: Added event: ' . print_r($event, true));
    }
    error_log("Nach NerdBar Filter: " . count($result));
    ob_start();
    ?>
    <div class="fsr-events">
    <?php foreach($result as $event): ?>
        <article class="fsr-event-card">
            <?php if($event['url'] !== '') { ?>
                <a href="<?php echo esc_url($event['url']); ?>">
                    <h5>
                        <?php echo esc_html($event['title']); ?>
                    </h5>
                </a>
            <?php } else { ?>
                <a>
                    <h5>
                        <?php echo esc_html($event['title']); ?>
                    </h5>
                </a>
            <?php } ?>
            <?php
            if($event['type'] === 'nerdbar') {
                echo '<small>Alle zwei Wochen</small>';
            }
            error_log('CALENDAR: Rendering event: ' . print_r($event, true));
            ?>
            <div class="fsr-event-date">
                <?php echo esc_html(
                    date_i18n(
                        'd.m.Y H:i',
                        $event['timestamp']
                    )
                ); ?>
                Uhr
            </div>
            <?php if($event['location']): ?>
            <div>
                📍
                <?php echo esc_html($event['location']); ?>
            </div>
            <?php endif; ?>
            <p>
                <?php if($event['description']): ?>
                    <?php echo esc_html(
                        wp_trim_words(
                            $event['description'],
                            20
                        )
                    ); ?>
                <?php endif; ?>
            </p>
        </article>
    <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}