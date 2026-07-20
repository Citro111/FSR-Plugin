<?php

if (!defined('ABSPATH')) exit;

/**
 * Speichert neue Office-Hour-Regeln über ein Frontend-Formular.
 * Erwartet eine geschützte Seite (z. B. passwortgeschützt).
 */
function fsr_office_hours_handle_submit() {

    if (empty($_POST['fsr_oh_submit'])) {
        return [false, ''];
    }

    if (!wp_verify_nonce($_POST['_fsr_oh_nonce'] ?? '', 'fsr_oh_submit')) {
        return [false, 'Ungültige Anfrage.'];
    }

    $settings = fsr_office_hours_get_settings();

    $title = sanitize_text_field($_POST['title'] ?? '');
    $location = sanitize_text_field($_POST['location'] ?? '');
    $start_time = fsr_office_hours_sanitize_time($_POST['start_time'] ?? '');
    $end_time = fsr_office_hours_sanitize_time($_POST['end_time'] ?? '');
    $recurrence = sanitize_key($_POST['recurrence'] ?? 'monthly_nth');
    $nth_week = absint($_POST['nth_week'] ?? 1);
    $weekday = absint($_POST['weekday'] ?? 3);
    $week_interval = absint($_POST['week_interval'] ?? 1);
    $reason = sanitize_text_field($_POST['reason'] ?? '');

    $incoming_ids = $_POST['member_ids'] ?? [];
    if (!is_array($incoming_ids)) {
        $incoming_ids = [$incoming_ids];
    }

    $member_ids = [];
    foreach ($incoming_ids as $id_value) {
        $member_id = absint($id_value);
        if ($member_id > 0) {
            $member_ids[] = $member_id;
        }
    }

    $member_ids = array_values(array_unique($member_ids));

    if ($title === '') {
        return [false, 'Bitte einen Titel angeben.'];
    }

    if (empty($member_ids)) {
        return [false, 'Bitte mindestens eine Person auswählen.'];
    }

    if (!in_array($recurrence, ['monthly_nth', 'weekly'], true)) {
        $recurrence = 'monthly_nth';
    }

    $rule = [
        'id' => 'rule_' . wp_generate_password(8, false, false),
        'title' => $title,
        'recurrence' => $recurrence,
        'nth_week' => max(1, min(4, $nth_week)),
        'weekday' => max(1, min(7, $weekday)),
        'week_interval' => max(1, min(8, $week_interval)),
        'start_time' => $start_time,
        'end_time' => $end_time,
        'location' => $location !== '' ? $location : 'FSR Büro',
        'member_ids' => $member_ids,
        'created_at' => current_time('mysql'),
        'created_by' => get_current_user_id(),
        'notes' => $reason,
    ];

    $settings['rules'] = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
    $settings['rules'][] = $rule;

    update_option('fsr_office_hours_settings', $settings);

    return [true, 'Office Hour erfolgreich gespeichert.'];
}

/**
 * Löscht eine Office-Hour-Regel anhand der ID.
 */
function fsr_office_hours_handle_delete() {

    if (empty($_POST['fsr_oh_delete_submit'])) {
        return [false, ''];
    }

    if (!wp_verify_nonce($_POST['_fsr_oh_delete_nonce'] ?? '', 'fsr_oh_delete_submit')) {
        return [false, 'Ungültige Anfrage.'];
    }

    $rule_id = sanitize_key($_POST['rule_id'] ?? '');
    if ($rule_id === '') {
        return [false, 'Keine Regel ausgewählt.'];
    }

    $settings = fsr_office_hours_get_settings();
    $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];

    $new_rules = [];
    $deleted = false;

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        if (($rule['id'] ?? '') === $rule_id) {
            $deleted = true;
            continue;
        }

        $new_rules[] = $rule;
    }

    if (!$deleted) {
        return [false, 'Regel nicht gefunden.'];
    }

    $settings['rules'] = $new_rules;
    update_option('fsr_office_hours_settings', $settings);

    return [true, 'Office Hour gelöscht.'];
}

/**
 * Optional: Nur erlaubte Mitglieder für die Auswahl anzeigen.
 * Du kannst hier deine eigene Logik einbauen.
 */
function fsr_office_hours_get_allowed_members() {
    $data = fsr_get_members_data('all');
    $members = $data['members'] ?? [];

    $allowed = [];

    foreach ($members as $member) {
        if (!is_array($member) || empty($member['id'])) {
            continue;
        }

        $role = strtolower((string) ($member['role'] ?? ''));
        $is_allowed =
            in_array($role, ['gewählt', 'gewaehlt', 'helfer', 'helper'], true)
            || !empty($member['office_hours_allowed']);

        if (!$is_allowed) {
            continue;
        }

        $allowed[] = [
            'id' => (int) $member['id'],
            'name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
        ];
    }

    return $allowed;
}

function fsr_office_hours_admin_shortcode($atts) {
    $settings = fsr_office_hours_get_settings();
    $members = fsr_office_hours_get_allowed_members();

    $ok = false;
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['fsr_oh_submit'])) {
            [$ok, $message] = fsr_office_hours_handle_submit();
        } elseif (isset($_POST['fsr_oh_delete_submit'])) {
            [$ok, $message] = fsr_office_hours_handle_delete();
        }
    }

    if (!empty($_GET['fsr_msg'])) {
        $message = sanitize_text_field($_GET['fsr_msg']);
        $ok = (($_GET['fsr_status'] ?? '') === 'success');
    }

    $weekday_labels = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];

    ob_start();
    ?>

```
<div class="fsr-office-hours-admin">

    <h3>Office Hours eintragen</h3>
    <p>Hier können Helfer und Gewählte ihre Sprechstunden direkt eintragen.</p>

    <?php if ($message !== '') : ?>
        <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="margin: 1em 0; padding: 12px;">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>

    <form method="post" style="margin-bottom: 2em;">
        <?php wp_nonce_field('fsr_oh_submit', '_fsr_oh_nonce'); ?>
        <input type="hidden" name="fsr_oh_submit" value="1" />

        <table class="form-table">
            <tr>
                <th><label for="fsr_oh_title">Titel</label></th>
                <td>
                    <input type="text" id="fsr_oh_title" name="title" class="regular-text" value="Office Hour" required />
                </td>
            </tr>

            <tr>
                <th><label for="fsr_oh_members">Personen</label></th>
                <td>
                    <select id="fsr_oh_members" name="member_ids[]" multiple size="6" style="min-width: 320px;" class="fsr-oh-select2" required>
                        <?php foreach ($members as $member) : ?>
                            <option value="<?php echo esc_attr($member['id']); ?>">
                                <?php echo esc_html($member['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Mehrfachauswahl möglich.</p>
                </td>
            </tr>

            <tr>
                <th><label for="fsr_oh_recurrence">Rhythmus</label></th>
                <td>
                    <select id="fsr_oh_recurrence" name="recurrence" class="fsr-oh-recurrence">
                        <option value="monthly_nth">Monatlich (n-ter Wochentag)</option>
                        <option value="weekly">Wöchentlich</option>
                    </select>
                </td>
            </tr>

            <tr class="fsr-oh-monthly-fields">
                <th>Monatliche Wiederholung</th>
                <td>
                    <label>
                        <select name="nth_week">
                            <option value="1">1.</option>
                            <option value="2">2.</option>
                            <option value="3">3.</option>
                            <option value="4">4.</option>
                        </select>
                        Woche
                    </label>

                    <label style="margin-left: 1em;">
                        <select name="weekday">
                            <?php foreach ($weekday_labels as $weekday_key => $weekday_label) : ?>
                                <option value="<?php echo esc_attr($weekday_key); ?>"><?php echo esc_html($weekday_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </td>
            </tr>

            <tr class="fsr-oh-weekly-fields" style="display:none;">
                <th>Wöchentliche Wiederholung</th>
                <td>
                    Alle
                    <input type="number" min="1" max="8" name="week_interval" value="1" style="width: 70px;" />
                    Wochen, am
                    <select name="weekday">
                        <?php foreach ($weekday_labels as $weekday_key => $weekday_label) : ?>
                            <option value="<?php echo esc_attr($weekday_key); ?>"><?php echo esc_html($weekday_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="fsr_oh_start_time">Zeit</label></th>
                <td>
                    <input type="time" id="fsr_oh_start_time" name="start_time" value="10:00" />
                    bis
                    <input type="time" id="fsr_oh_end_time" name="end_time" value="12:00" />
                </td>
            </tr>

            <tr>
                <th><label for="fsr_oh_location">Ort</label></th>
                <td>
                    <input type="text" id="fsr_oh_location" name="location" class="regular-text" value="FSR Büro" />
                </td>
            </tr>

            <tr>
                <th><label for="fsr_oh_reason">Hinweis</label></th>
                <td>
                    <input type="text" id="fsr_oh_reason" name="reason" class="regular-text" placeholder="Optionaler Hinweis" />
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Office Hour speichern</button>
        </p>
    </form>

    <hr />

    <h3>Vorhandene Office Hours</h3>

    <?php if (empty($rules)) : ?>
        <p>Es sind noch keine Office Hours angelegt.</p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th>Personen</th>
                    <th>Rhythmus</th>
                    <th>Zeit</th>
                    <th>Ort</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule) : ?>
                    <?php
                    $rule = fsr_office_hours_sanitize_rule($rule);
                    $member_names = [];

                    foreach (($rule['member_ids'] ?? []) as $member_id) {
                        foreach ($members as $member) {
                            if ((int) $member['id'] === (int) $member_id) {
                                $member_names[] = $member['name'];
                                break;
                            }
                        }
                    }

                    $rhythmus = '';
                    if (($rule['recurrence'] ?? '') === 'weekly') {
                        $rhythmus = 'Alle ' . (int) ($rule['week_interval'] ?? 1) . ' Wochen, ' . $weekday_labels[(int) ($rule['weekday'] ?? 3)] ?? 'Mittwoch';
                    } else {
                        $rhythmus = (int) ($rule['nth_week'] ?? 1) . '. ' . ($weekday_labels[(int) ($rule['weekday'] ?? 3)] ?? 'Mittwoch') . ' im Monat';
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($rule['title']); ?></strong>
                            <?php if (!empty($rule['notes'])) : ?>
                                <br><span class="description"><?php echo esc_html($rule['notes']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(implode(', ', $member_names)); ?></td>
                        <td><?php echo esc_html($rhythmus); ?></td>
                        <td><?php echo esc_html($rule['start_time'] . ' – ' . $rule['end_time']); ?></td>
                        <td><?php echo esc_html($rule['location']); ?></td>
                        <td style="text-align:right;">
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('fsr_oh_delete_submit', '_fsr_oh_delete_nonce'); ?>
                                <input type="hidden" name="fsr_oh_delete_submit" value="1" />
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>" />
                                <button type="submit" class="button button-link-delete" onclick="return confirm('Diese Office Hour wirklich löschen?');">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
    jQuery(function($) {
        function toggleRecurrenceFields() {
            const mode = $('.fsr-oh-recurrence').val();
            $('.fsr-oh-monthly-fields').toggle(mode === 'monthly_nth');
            $('.fsr-oh-weekly-fields').toggle(mode === 'weekly');
        }

        $('.fsr-oh-recurrence').on('change', toggleRecurrenceFields);
        toggleRecurrenceFields();

        if ($.fn.select2) {
            $('.fsr-oh-select2').select2({ width: '100%' });
        }
    });
</script>
<?php
return ob_get_clean();
```

}

add_shortcode('fsr_office_hours_admin', 'fsr_office_hours_admin_shortcode');
