<?php

function fsr_office_hours_handle_sick_submit() {

    if (empty($_POST['fsr_oh_sick_submit'])) {
        return [false, ''];
    }

    if (!wp_verify_nonce($_POST['_fsr_oh_sick_nonce'] ?? '', 'fsr_oh_sick_submit')) {
        return [false, 'Ungültige Anfrage.'];
    }

    $member_id = absint($_POST['member_id'] ?? 0);
    $occ_key   = sanitize_text_field($_POST['occ_key'] ?? '');
    $reason    = sanitize_text_field($_POST['reason'] ?? '');

    [$rule_id, $date] = explode('|', $occ_key);

    $settings = fsr_office_hours_get_settings();

    foreach (($settings['cancellations'] ?? []) as $key => $entry) {

        if (
            ($entry['rule_id'] ?? '') === $rule_id &&
            absint($entry['member_id'] ?? 0) === $member_id &&
            ($entry['occurrence_date'] ?? '') === $date
        ) {
            unset($settings['cancellations'][$key]);
            $settings['cancellations'] = array_values($settings['cancellations']);

            update_option('fsr_office_hours_settings', $settings);

            return [true, 'Termin wieder zugesagt.'];
        }
    }

    $settings['cancellations'][] = [
        'rule_id' => $rule_id,
        'member_id' => $member_id,
        'occurrence_date' => $date,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
    ];

    update_option('fsr_office_hours_settings', $settings);

    return [true, 'Termin erfolgreich abgesagt.'];
}

function fsr_office_hours_sick_shortcode($atts) {
    $settings = fsr_office_hours_get_settings();
    $members  = fsr_get_members_data('all')['members'];

    $ok = false;
    $message = '';

    // Auswahl merken: zuerst aus GET, dann aus POST
    $member_id = absint($_GET['member_id'] ?? $_POST['member_id'] ?? 0);
    $selected_occ_key = sanitize_text_field($_GET['occ_key'] ?? $_POST['occ_key'] ?? '');

    // POST nur für Absage / Wiederzusage
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fsr_oh_sick_submit'])) {
        [$ok, $message] = fsr_office_hours_handle_sick_submit();

        $redirect = add_query_arg([
            'member_id'  => $member_id,
            'occ_key'    => $selected_occ_key,
            'fsr_msg'    => $message,
            'fsr_status' => $ok ? 'success' : 'error',
        ], get_permalink());

        wp_safe_redirect($redirect);
        exit;
    }

    if (!empty($_GET['fsr_msg'])) {
        $message = sanitize_text_field($_GET['fsr_msg']);
        $ok = (($_GET['fsr_status'] ?? '') === 'success');
    }

    $current_member = null;
    foreach ($members as $m) {
        if ((int) $m['id'] === $member_id) {
            $current_member = $m;
            break;
        }
    }

    ob_start();
    echo '<div class="fsr-office-hours-sick">';

    // Mitbewerter-Auswahl per GET, damit sie beim Refresh erhalten bleibt
    echo '<form method="get">';
    echo '<label>Mitglied</label><br>';
    echo '<select name="member_id" onchange="this.form.submit()">';
    echo '<option value="" selected style="display: none;">';
    echo 'Bitte wählen';
    echo '</option>';

    foreach ($members as $m) {
        $full_name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        echo '<option value="' . esc_attr($m['id']) . '" ' . selected($m['id'], $member_id, false) . '>';
        echo esc_html($full_name);
        echo '</option>';
    }

    echo '</select>';
    echo '</form>';

    if ($member_id <= 0 || !$current_member) {
        echo '</div>';
        return ob_get_clean();
    }

    echo '<p>Hallo ' . esc_html($current_member['first_name']) . ', hier kannst du den nächsten Termin absagen oder wieder zusagen.</p>';

    $occurrences = fsr_office_hours_collect_occurrences($settings['rules'], 25, false);
    $today = current_time('Y-m-d');
    $choices = [];

    foreach ($occurrences as $occurrence) {
        if ($occurrence['date'] < $today) {
            continue;
        }
        if (!in_array($member_id, $occurrence['member_ids'], true)) {
            continue;
        }
        $choices[] = $occurrence;
    }

    if ($message !== '') {
        echo '<p class="fsr-office-hours-sick-message ' . ($ok ? 'is-success' : 'is-error') . '">' . esc_html($message) . '</p>';
    }

    if (empty($choices)) {
        echo '<p>Du hast aktuell keine kommenden Office-Hours-Termine.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    // Wenn noch nichts ausgewählt ist, ersten Termin als Default nehmen
    if ($selected_occ_key === '') {
        $selected_occ_key = $choices[0]['rule_id'] . '|' . $choices[0]['date'];
    }

    echo '<form method="post">';
    wp_nonce_field('fsr_oh_sick_submit', '_fsr_oh_sick_nonce');
    echo '<input type="hidden" name="fsr_oh_sick_submit" value="1" />';
    echo '<input type="hidden" name="member_id" value="' . esc_attr($member_id) . '" />';

    echo '<label>Nächster Termin:</label><br>';
    echo '<select name="occ_key" id="fsr_oh_rule_selector">';

    foreach ($choices as $choice) {
        $cancelled = fsr_office_hours_member_is_cancelled(
            $settings['cancellations'],
            $choice['rule_id'],
            $choice['date'],
            $member_id
        );

        $label =
            date_i18n('d.m.Y', strtotime($choice['date'])) .
            ' - ' .
            $choice['title'] .
            ' (' .
            $choice['start_time'] .
            '-' .
            $choice['end_time'] .
            ')' .
            ($cancelled ? ' (bereits abgesagt)' : '');

        $value = $choice['rule_id'] . '|' . $choice['date'];

        echo '<option value="' . esc_attr($value) . '" ' . selected($value, $selected_occ_key, false) . ' data-cancelled="' . ($cancelled ? '1' : '0') . '">';
        echo esc_html($label);
        echo '</option>';
    }

    echo '</select>';

    echo '<p><label>Optionaler Grund:</label><br><input type="text" name="reason" class="regular-text" /></p>';
    echo '<button id="fsr-oh-submit" type="submit" class="button button-primary">Termin absagen</button>';
    echo '</form>';
    echo '</div>';
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.getElementById('fsr_oh_rule_selector');
            const button = document.getElementById('fsr-oh-submit');

            if (!select || !button) return;

            function updateButton() {
                const cancelled = select.options[select.selectedIndex].dataset.cancelled === '1';
                button.textContent = cancelled ? 'Termin wieder zusagen' : 'Termin absagen';
            }

            updateButton();
            select.addEventListener('change', updateButton);
        });
    </script>
    <?php

    return ob_get_clean();
}