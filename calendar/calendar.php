<?php
/**
 * FSR Website Tools
 * Calendar integration
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/admin-template.php';
/*
|--------------------------------------------------------------------------
| Einstellungen
|--------------------------------------------------------------------------
*/
define('FSR_CALENDAR_URL', 'fsr_calendar_url');
add_action('admin_init', function () {
    register_setting(
        'fsr_settings',
        FSR_CALENDAR_URL
    );
});
/*
|--------------------------------------------------------------------------
| Shortcode
|--------------------------------------------------------------------------
|
| Nutzung:
|
| [fsr_events]
| [fsr_events count="3"]
|
|--------------------------------------------------------------------------
*/
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
            <h3>
                <?php echo esc_html($event['title']); ?>
            </h3>
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
/*
|--------------------------------------------------------------------------
| Kalender laden
|--------------------------------------------------------------------------
*/
function fsr_get_calendar_events($url){
    $response = wp_remote_get(
        $url,
        [
            'timeout'=>15
        ]
    );
    if(
        is_wp_error($response)
    ){
        return [];
    }
    $data = wp_remote_retrieve_body($response);
    if(!$data){
        return [];
    }
    return fsr_parse_ical($data);
}
/*
|--------------------------------------------------------------------------
| Minimaler iCal Parser
|--------------------------------------------------------------------------
*/
function fsr_parse_ical($ical){
    $events = [];
    preg_match_all(
        '/BEGIN:VEVENT(.*?)END:VEVENT/s',
        $ical,
        $matches
    );
    foreach($matches[1] as $raw){
        preg_match(
            '/SUMMARY:(.*)/',
            $raw,
            $title
        );
        preg_match(
            '/DTSTART[^:]*:(.*)/',
            $raw,
            $date
        );
        preg_match(
            '/LOCATION:(.*)/',
            $raw,
            $location
        );
        preg_match(
            '/DESCRIPTION:(.*)/',
            $raw,
            $description
        );
        if(
            empty($title[1]) ||
            empty($date[1])
        ){
            continue;
        }
        $date_string = trim($date[1]);
        $timestamp = strtotime(
            $date_string
        );
        if(
            $timestamp < time()
        ){
            continue;
        }
        $events[] = [
            'title'=>trim($title[1]),
            'timestamp'=>$timestamp,
            'location'=>isset($location[1])
                ? trim($location[1])
                : '',
            'description'=>isset($description[1])
                ? trim($description[1])
                : ''
        ];
    }
    return $events;
}