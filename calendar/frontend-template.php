<?php
if (!defined('ABSPATH')) {
    exit;
}
add_shortcode('fsr_events', 'fsr_render_events');
function fsr_render_events($atts) {
    $atts = shortcode_atts(
        [
            'count' => 5
        ],
        $atts
    );
    $count = intval($atts['count']);
    $calendar_url = get_option(FSR_CALENDAR_URL);
    if (!$calendar_url) {
        return '<p>Kein Kalender hinterlegt.</p>';
    }
    $events = fsr_get_calendar_events($calendar_url);
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
    foreach($events as $event){
        if(
            stripos($event['title'], 'nerdbar') !== false
        ){
            if($nerdbar_found){
                continue;
            }
            $nerdbar_found = true;
        }
        $result[] = $event;
        if(count($result) >= $count){
            break;
        }
    }
    ob_start();
    ?>
    <div class="fsr-events">
    <?php foreach($result as $event): ?>
        <article class="fsr-event-card">
            <h5 href="<?php echo esc_url($event['url']); ?>">
                <?php echo esc_html($event['title']); ?>
            </h5>
            <?php
            if($event['type'] === 'nerdbar') {
                echo '<small>Alle zwei Wochen</small>';
            }
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
            <?php if($event['description']): ?>
            <p>
                <?php echo esc_html(
                    wp_trim_words(
                        $event['description'],
                        20
                    )
                ); ?>
            </p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}