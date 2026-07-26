<?php
/**
 * FSR Calendar Settings Template
 */
if (!defined('ABSPATH')) {
    exit;
}
register_setting(
    'fsr_settings',
    'fsr_calendar_categories'
);
function fsr_calendar_render_admin_interface() {
    ?>
    <div class="wrap">
        <h1>Kalender Einstellungen</h1>
        <p>
            Hier wird der öffentliche Google Kalender hinterlegt.
            Die Webseite liest daraus automatisch kommende Veranstaltungen.
        </p>
        <form method="post" action="options.php">
            <?php
            settings_fields('fsr_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        Google Kalender iCal URL
                    </th>
                    <td>
                        <input
                            type="url"
                            name="<?php echo esc_attr(FSR_CALENDAR_URL); ?>"
                            value="<?php echo esc_attr(
                                get_option(FSR_CALENDAR_URL)
                            ); ?>"
                            style="width:600px;"
                            placeholder="https://calendar.google.com/calendar/ical/..."
                        >
                        <p class="description">
                            Diese URL findest du in Google Kalender unter:
                            <br>
                            Kalender → Einstellungen → Kalender integrieren →
                            Öffentliche Adresse im iCal-Format
                        </p>
                    </td>
                </tr>
            </table>
            <h2>Kategorien</h2>
            <p>
                Hier können Links zu den verschiedenen Veranstaltungskategorien hinterlegt werden.
                Die Webseite zeigt dann automatisch die passende Kategorie an.
            </p>
            <button 
                type="button"
                class="button button-primary"
                id="add-category-btn">
                <span class="dashicons dashicons-plus" style="font-size:16px; vertical-align:middle; margin-top:-2px;"></span>
                Kategorie hinzufügen
            </button>
            <table class="form-table" id="category-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Weitere Namen (optional)</th>
                        <th>URL</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody id="category-table-body">
                    <?php
                    $categories = get_option(
                        'fsr_calendar_categories',
                        []
                    );
                    if (!is_array($categories)) {
                        $categories = [];
                    }
                    foreach ($categories as $index => $category): ?>
                    <tr>
                        <td>
                            <input 
                            type="text"
                            name="fsr_calendar_categories[<?php echo $index; ?>][name]"
                            value="<?php echo esc_attr($category['name']); ?>"
                            >
                        </td>
                        <td>
                            <input 
                            type="text"
                            name="fsr_calendar_categories[<?php echo $index; ?>][additionalNames]"
                            value="<?php echo esc_attr(
                            implode(',', $category['additionalNames'] ?? [])
                            ); ?>"
                            >
                        </td>
                        <td>
                            <input 
                            type="url"
                            name="fsr_calendar_categories[<?php echo $index; ?>][url]"
                            value="<?php echo esc_attr($category['url']); ?>"
                            >
                        </td>
                        <td>
                            <button class="button remove-category">
                                Entfernen
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>  
            <?php submit_button('Speichern'); ?>
        </form>
    </div>
    
    <script>
        jQuery(document).ready(function($) {
            // Add a new category row
            $('#add-category-btn').click(function(e) {
                e.preventDefault();
                var newRow = '<tr>' +
                    '<td><input type="text" name="fsr_calendar_categories[][name]" placeholder="Kategorie Name"></td>' +
                    '<td><input type="text" name="fsr_calendar_categories[][additionalNames]" placeholder="Weitere Namen (optional)"></td>' +
                    '<td><input type="url" name="fsr_calendar_categories[][url]" placeholder="Kategorie URL"></td>' +
                    '<td><button class="remove-category">Entfernen</button></td>' +
                    '</tr>';
                $('#category-table tbody').append(newRow);
            });
            // Remove a category row
            $(document).on('click', '.remove-category', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });
        });
    </script>
    <?php
}