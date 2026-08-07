<?php

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('fsr_events', 'fsr_etit_calendar_shortcode');

function fsr_etit_calendar_shortcode($atts): string {
    $atts = shortcode_atts(
        ['count' => 5, 'category' => ''],
        $atts,
        'fsr_events'
    );
    $count = max(1, min(50, absint($atts['count'])));
    $category = sanitize_title($atts['category']);
    $calendar_url = get_option(FSR_ETIT_OPTION_CALENDAR_URL, '');
    if (!$calendar_url) {
        return '<p>Kein Kalender hinterlegt.</p>';
    }

    $events = array_filter(
        fsr_etit_calendar_get_events($calendar_url),
        static fn($event): bool => (int) ($event['timestamp'] ?? 0) >= time()
    );
    if ($category !== '') {
        $events = array_filter(
            $events,
            static fn($event): bool => ($event['type'] ?? '') === $category
        );
    }
    if (!$events) {
        return '<p>Keine Veranstaltungen gefunden.</p>';
    }

    usort($events, static fn($a, $b): int => (int) $a['timestamp'] <=> (int) $b['timestamp']);
    $result = [];
    $nerdbar_found = false;
    foreach ($events as $event) {
        if ($category === '' && ($event['type'] ?? '') === 'nerdbar') {
            if ($nerdbar_found) {
                continue;
            }
            $nerdbar_found = true;
        }
        $result[] = $event;
        if (count($result) >= $count) {
            break;
        }
    }

    ob_start();
    ?>
    <div class="fsr-events">
        <?php foreach ($result as $event) : ?>
            <article class="fsr-event-card">
                <h3 class="fsr-event-title">
                    <?php
                    $event_url = (string) ($event['url'] ?? '');
                    $event_page_id = $event_url !== '' ? url_to_postid($event_url) : 0;
                    if ($event_url !== '' && $event_page_id !== get_queried_object_id()) :
                        ?>
                        <a href="<?php echo esc_url($event_url); ?>"><?php echo esc_html($event['title']); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($event['title']); ?>
                    <?php endif; ?>
                </h3>
                <?php if (preg_match('/(?:^|;)INTERVAL=2(?:;|$)/', (string) ($event['rrule'] ?? ''))) : ?>
                    <small>Alle zwei Wochen</small>
                <?php endif; ?>
                <div class="fsr-event-date">
                    <?php echo esc_html(wp_date('d.m.Y H:i', (int) $event['timestamp'], wp_timezone())); ?> Uhr
                </div>
                <?php if (!empty($event['location'])) : ?>
                    <div>📍 <?php echo esc_html($event['location']); ?></div>
                <?php endif; ?>
                <?php if (!empty($event['description'])) : ?>
                    <p><?php echo esc_html(wp_trim_words($event['description'], 20)); ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
