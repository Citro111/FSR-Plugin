<?php
function fsr_office_hours_member_token($member_id) {
    return substr(hash_hmac('sha256', (string) absint($member_id), wp_salt('auth')), 0, 20);
}

function fsr_office_hours_validate_member_token($member_id, $token) {
    $member_id = absint($member_id);
    $token = trim((string) $token);
    if ($member_id <= 0 || $token === '') {
        return false;
    }
    return hash_equals(fsr_office_hours_member_token($member_id), $token);
}

function fsr_office_hours_handle_sick_submit($settings, $member_id, $token) {
    if (empty($_POST['fsr_oh_sick_submit'])) {
        return [false, ''];
    }

    if (!wp_verify_nonce($_POST['_fsr_oh_sick_nonce'] ?? '', 'fsr_oh_sick_submit')) {
        return [false, 'Ungültige Anfrage. Bitte Seite neu laden.'];
    }

    if (!fsr_office_hours_validate_member_token($member_id, $token)) {
        return [false, 'Token ungültig.'];
    }

    $occ_key = sanitize_text_field($_POST['occ_key'] ?? '');
        if (!str_contains($occ_key, '|')) {
            return [false, 'Ungültiger Termin.'];
        }
        [$rule_id, $date] = explode('|', $occ_key);
    $reason = sanitize_text_field((string) ($_POST['reason'] ?? ''));

    if ($rule_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [false, 'Bitte einen gültigen Termin auswählen.'];
    }

    foreach ($settings['cancellations'] as $entry) {
        if (($entry['rule_id'] ?? '') === $rule_id && absint($entry['member_id'] ?? 0) === $member_id && ($entry['occurrence_date'] ?? '') === $date) {
            return [true, 'Dieser Termin ist bereits als abgesagt markiert.'];
        }
    }

    return [true, 'Der Termin wurde für dich entfernt.'];
}

function fsr_office_hours_sick_shortcode($atts) {
    $atts = shortcode_atts(['member' => '', 'token' => ''], $atts);
    $settings = fsr_office_hours_get_settings();
    $members_map = fsr_office_hours_get_members_map();
    $member_id = absint($_GET['fsr_oh_member'] ?? $atts['member']);
    $token = sanitize_text_field((string) ($_GET['fsr_oh_token'] ?? $atts['token']));

    if ($member_id <= 0 || !isset($members_map[$member_id])) {
        return '<div class="fsr-office-hours-sick">Ungültiger Krankmeldelink (Mitglied fehlt).</div>';
    }

    if (!fsr_office_hours_validate_member_token($member_id, $token)) {
        return '<div class="fsr-office-hours-sick">Ungültiger Krankmeldelink (Token stimmt nicht).</div>';
    }

    [$ok, $message] = fsr_office_hours_handle_sick_submit($settings, $member_id, $token);
    $settings = fsr_office_hours_get_settings();

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
    echo '<p>Hallo ' . esc_html($members_map[$member_id]['name']) . ', hier kannst du den nächsten Termin absagen.</p>';

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

    return ob_get_clean();
}