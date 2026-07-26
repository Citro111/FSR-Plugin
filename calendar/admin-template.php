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
    </div>
    <?php
}