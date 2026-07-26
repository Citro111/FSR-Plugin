<?php
/**
 * FSR Calendar Settings Template
 */
if (!defined('ABSPATH')) {
    exit;
}
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
            <?php submit_button('Kalender speichern'); ?>
        </form>
        <form>
            <h2>Kategorien</h2>
            <p>
                Hier können Links zu den verschiedenen Veranstaltungskategorien hinterlegt werden.
                Die Webseite zeigt dann automatisch die passende Kategorie an.
            </p>
            <button id="add-category" class="button button-primary">Kategorie hinzufügen</button>
            <table class="form-table" id="category-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Weitere Namen (optional)</th>
                        <th>URL</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $categories = get_option('fsr_calendar_categories', []);
                    foreach ($categories as $category) {
                        echo '<tr>';
                        echo '<td><input type="text" name="fsr_calendar_categories[][name]" value="' . esc_attr($category['name']) . '" placeholder="Kategorie Name"></td>';
                        echo '<td><input type="text" name="fsr_calendar_categories[][additionalNames][]" value="' . esc_attr(implode(',', $category['additionalNames'] ?? [])) . '" placeholder="Weitere Namen (optional)"></td>';
                        echo '<td><input type="url" name="fsr_calendar_categories[][url]" value="' . esc_url($category['url']) . '" placeholder="Kategorie URL"></td>';
                        echo '<td><button class="remove-category">Entfernen</button></td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>   
            </table>  
        </form>   
        <button id="save-categories" class="button button-primary">Kategorien speichern</button>
    </div>
    <script>
    jQuery(document).ready(function($) {
        // Add a new category row
        $('#add-category').click(function(e) {
            e.preventDefault();
            var newRow = '<tr>' +
                '<td><input type="text" name="fsr_calendar_categories[][name]" placeholder="Kategorie Name"></td>' +
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

        function saveCategories() {
            var categories = [];
            $('#category-table tbody tr').each(function() {
                var name = $(this).find('input[name="fsr_calendar_categories[][name]"]').val();
                var url = $(this).find('input[name="fsr_calendar_categories[][url]"]').val();
                if (name && url) {
                    categories.push({
                        name: name,
                        url: url
                    });
                }
            });
            $.post(ajaxurl, {
                action: 'save_calendar_categories',
                categories: categories
            }, function(response) {
                alert('Kategorien gespeichert!');
            });
        }

        function createCategoryRow(name, url) {
            return '<tr>' +
                '<td><input type="text" name="fsr_calendar_categories[][name]" value="' + name + '" placeholder="Kategorie Name"></td>' +
                '<td><input type="text" name="fsr_calendar_categories[][additionalNames][]" value="" placeholder="Weitere Namen (optional)"></td>' +
                '<td><input type="url" name="fsr_calendar_categories[][url]" value="' + url + '" placeholder="Kategorie URL"></td>' +
                '<td><button class="remove-category">Entfernen</button></td>' +
                '</tr>';
        }
    });
    <?php
}