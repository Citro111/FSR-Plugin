<?php

function fsr_office_hours_handle_sick_submit($settings) {
    $member_id = absint($_POST['member_id'] ?? 0);
    $occ_key = sanitize_text_field($_POST['occ_key'] ?? '');
    $reason = sanitize_text_field($_POST['reason'] ?? '');
    if (!str_contains($occ_key, '|')) {
        return [false, 'Ungültiger Termin.'];
    }
    [$rule_id, $date] = explode('|', $occ_key);
        $settings['cancellations'][] = [
        'rule_id' => $rule_id,
        'member_id' => $member_id,
        'occurrence_date' => $date,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
    ];

    update_option(
        'fsr_office_hours_settings',
        $settings
    );

    return [true, 'Termin erfolgreich abgesagt.'];
}

function fsr_office_hours_sick_shortcode($atts) {
    $settings = fsr_office_hours_get_settings();
    $members = fsr_get_members_data('all')['members'];
    [$ok, $message] = fsr_office_hours_handle_sick_submit($settings);
    $member_id = absint($_POST['member_id'] ?? 0);
    echo '<select name="member_id">';
    foreach ($members as $member) {
        echo '<option value="' . esc_attr($member['id']) . '"';
        selected($member['id'], $member_id);
        echo '>';
        echo esc_html(
            $member['first_name'] . ' ' .
            $member['last_name']
        );
        echo '</option>';
    }

    $occurrences = fsr_office_hours_collect_occurrences($settings['rules'], 25);
    $choices = [];

    foreach ($occurrences as $occurrence) {
        if (!in_array($member_id, $occurrence['member_ids'], true)) {
            continue;
        }
        $choices[] = $occurrence;
    }

    ob_start();
    echo '<div class="fsr-office-hours-sick">';
    echo '<h3>Krankmeldung Office Hours</h3>';
    echo '<p>Hallo ' . esc_html($members_map[$member_id]['first_name']) . ', hier kannst du den nächsten Termin absagen.</p>';

    if ($message !== '') {
        echo '<p class="fsr-office-hours-sick-message ' . ($ok ? 'is-success' : 'is-error') . '">' . esc_html($message) . '</p>';
    }

    if (empty($choices)) {
        echo '<p>Du hast aktuell keine kommenden Office-Hours-Termine.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    echo '<form method="post">';
    wp_nonce_field('fsr_oh_sick_submit', '_fsr_oh_sick_nonce');
    echo '<input type="hidden" name="fsr_oh_sick_submit" value="1" />';

    echo '<label>Nächster Termin:</label><br>';
    echo '<select name="occ_key" id="fsr_oh_rule_selector">';
    foreach ($choices as $idx => $choice) {
        $label = date_i18n('d.m.Y', strtotime($choice['date'])) . ' - ' . $choice['title'] . ' (' . $choice['start_time'] . '-' . $choice['end_time'] . ')';
        $value = $choice['rule_id'] . '|' . $choice['date'];
        echo '<option value="' . esc_attr($value) . '" ' . selected($idx === 0, true, false) . '>'
            . esc_html($label)
            . '</option>';    }
    echo '</select>';

    $default_date = $choices[0]['date'];

    echo '<p><label>Optionaler Grund:</label><br><input type="text" name="reason" class="regular-text" /></p>';
    echo '<button type="submit" class="button button-primary">Termin als krank melden</button>';
    echo '</form>';

    $settings['cancellations'][] = [
        'rule_id' => $rule_id,
        'member_id' => $member_id,
        'occurrence_date' => $date,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
    ];

    update_option(
        'fsr_office_hours_settings',
        $settings
    );

    return ob_get_clean();
}