<?php

if (!defined('ABSPATH')) {
    exit;
}

function fsr_etit_calendar_render_admin_interface(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $categories = fsr_etit_calendar_sanitize_categories(
        get_option(FSR_ETIT_OPTION_CALENDAR_CATEGORIES, [])
    );
    $pages = get_pages([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'sort_column' => 'post_title',
        'sort_order'  => 'ASC',
        'number'      => 0,
    ]);
    $page_data = array_map(
        static fn($page): array => ['id' => (int) $page->ID, 'title' => (string) $page->post_title],
        $pages
    );
    ?>
    <div class="wrap">
        <h2>Kalender-Einstellungen</h2>
        <p>Hier wird der öffentliche iCal-Kalender für kommende Veranstaltungen hinterlegt.</p>
        <?php settings_errors(FSR_ETIT_OPTION_CALENDAR_URL); ?>
        <form method="post" action="options.php">
            <?php settings_fields('fsr_etit_calendar_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fsr-etit-calendar-url">Öffentliche iCal-URL</label></th>
                    <td>
                        <input
                            id="fsr-etit-calendar-url"
                            type="url"
                            class="large-text"
                            name="<?php echo esc_attr(FSR_ETIT_OPTION_CALENDAR_URL); ?>"
                            value="<?php echo esc_attr(fsr_etit_calendar_normalize_url(get_option(FSR_ETIT_OPTION_CALENDAR_URL, ''))); ?>"
                            placeholder="https://calendar.google.com/calendar/ical/..."
                        >
                        <p class="description">Aus Sicherheitsgründen ist ausschließlich eine öffentliche HTTPS-URL zulässig.</p>
                    </td>
                </tr>
            </table>

            <h2>Kategorien</h2>
            <p>Kategorien ordnen Veranstaltungstitel einer veröffentlichten WordPress-Seite zu.</p>
            <button type="button" class="button button-primary" id="fsr-etit-add-category">
                Kategorie hinzufügen
            </button>
            <table class="form-table" id="fsr-etit-category-table">
                <thead>
                    <tr><th>Name</th><th>Weitere Namen</th><th>Zielseite</th><th>Aktion</th></tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $index => $category) :
                    $additional = $category['additionalNames'] ?? [];
                    $additional = is_array($additional) ? implode(', ', $additional) : (string) $additional;
                    ?>
                    <tr>
                        <td>
                            <input type="text" name="<?php echo esc_attr(FSR_ETIT_OPTION_CALENDAR_CATEGORIES); ?>[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($category['name'] ?? ''); ?>">
                        </td>
                        <td>
                            <input type="text" name="<?php echo esc_attr(FSR_ETIT_OPTION_CALENDAR_CATEGORIES); ?>[<?php echo esc_attr($index); ?>][additionalNames]" value="<?php echo esc_attr($additional); ?>">
                        </td>
                        <td>
                            <select class="fsr-etit-category-page" name="<?php echo esc_attr(FSR_ETIT_OPTION_CALENDAR_CATEGORIES); ?>[<?php echo esc_attr($index); ?>][page_id]" style="width:300px;">
                                <option value="">– Seite auswählen –</option>
                                <?php foreach ($pages as $page) : ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>" <?php selected((int) ($category['page_id'] ?? 0), (int) $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><button type="button" class="button fsr-etit-remove-category">Entfernen</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button('Kalender speichern'); ?>
        </form>
    </div>

    <script>
    jQuery(function($) {
        const pages = <?php echo wp_json_encode($page_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const optionName = <?php echo wp_json_encode(FSR_ETIT_OPTION_CALENDAR_CATEGORIES); ?>;
        let categoryIndex = $('#fsr-etit-category-table tbody tr').length;

        function enhanceSelects(scope) {
            if ($.fn.select2) {
                scope.find('.fsr-etit-category-page').select2({
                    width: '300px',
                    placeholder: 'Seite suchen...',
                    allowClear: true
                });
            }
        }

        $('#fsr-etit-add-category').on('click', function() {
            const index = categoryIndex++;
            const row = $('<tr>');
            $('<td>').append($('<input>', {
                type: 'text',
                name: optionName + '[' + index + '][name]',
                placeholder: 'Kategorie'
            })).appendTo(row);
            $('<td>').append($('<input>', {
                type: 'text',
                name: optionName + '[' + index + '][additionalNames]',
                placeholder: 'Kommagetrennt'
            })).appendTo(row);

            const select = $('<select>', {
                class: 'fsr-etit-category-page',
                name: optionName + '[' + index + '][page_id]'
            }).css('width', '300px').append($('<option>', { value: '', text: '– Seite auswählen –' }));
            pages.forEach(function(page) {
                select.append($('<option>', { value: page.id, text: page.title }));
            });
            $('<td>').append(select).appendTo(row);
            $('<td>').append($('<button>', {
                type: 'button',
                class: 'button fsr-etit-remove-category',
                text: 'Entfernen'
            })).appendTo(row);

            $('#fsr-etit-category-table tbody').append(row);
            enhanceSelects(row);
        });

        $(document).on('click', '.fsr-etit-remove-category', function() {
            $(this).closest('tr').remove();
        });

        enhanceSelects($(document));
    });
    </script>
    <?php
}
