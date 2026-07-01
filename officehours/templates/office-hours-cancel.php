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
            $entry['rule_id'] === $rule_id &&
            absint($entry['member_id']) === $member_id &&
            $entry['occurrence_date'] === $date
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
    $members = fsr_get_members_data('all')['members'];
    $current_member = null;
    $ok = false;
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fsr_oh_sick_submit'])) {
        [$ok, $message] = fsr_office_hours_handle_sick_submit();
        // Redirect verhindert Refresh-Bug
        wp_safe_redirect(
            add_query_arg('fsr_msg', urlencode($message), remove_query_arg('fsr_msg'))
        );
        exit;
    }
    if (!empty($_GET['fsr_msg'])) {
        $message = sanitize_text_field($_GET['fsr_msg']);
        $ok = str_contains($message, 'zugesagt');
    }
    $member_id = absint($_POST['member_id'] ?? 0);

    ob_start();
    echo '<div class="fsr-office-hours-sick">';
    echo '<h3>Krankmeldung Office Hours</h3>';
    echo '<p>';
        echo '<label>Mitglied</label><br>';
        echo '<form method="post">';
            echo '<select name="member_id" onchange="this.form.submit()">';
            foreach ($members as $m) {
                echo '<option value="' . esc_attr($m['id']) . '"';
                selected($m['id'], $member_id);
                echo '>';
                echo esc_html(
                    $m['first_name'].' '.$m['last_name']
                );
                echo '</option>';
                if ($m['id'] == $member_id) {
                    $current_member = $m;
                }
            }
            echo '</select>';
        echo '</form>';
    echo '</p>';
    if ($member_id > 0 && $current_member) {
        echo '<form method="post">';
        wp_nonce_field('fsr_oh_sick_submit', '_fsr_oh_sick_nonce');
        echo '<input type="hidden" name="fsr_oh_sick_submit" value="1">';
        echo '<input type="hidden" name="member_id" value="'.$member_id.'">';
        echo '<p>Hallo ' . esc_html($current_member['first_name']) . ', hier kannst du den nächsten Termin absagen.</p>';
        $occurrences = fsr_office_hours_collect_occurrences($settings['rules'], 25, false // include fully cancelled occurrences
        );
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

        echo '<label>Nächster Termin:</label><br>';
        echo '<select name="occ_key" id="fsr_oh_rule_selector">';
        foreach ($choices as $choice) {
            $cancelled = fsr_office_hours_member_is_cancelled(
                $settings['cancellations'],
                $choice['rule_id'],
                $choice['date'],
                $member_id
            );
            $status = $cancelled
                ? ' (bereits abgesagt)'
                : '';
            $label =
                date_i18n('d.m.Y', strtotime($choice['date'])) .
                ' - ' .
                $choice['title'] .
                ' (' .
                $choice['start_time'] .
                '-' .
                $choice['end_time'] .
                ')' .
                $status;
            echo '<option value="' . esc_attr($choice['rule_id'].'|'.$choice['date']) . '"
            data-cancelled="' . ($cancelled ? '1' : '0') . '">';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
        echo '<p><label>Optionaler Grund:</label><br><input type="text" name="reason" class="regular-text" /></p>';
        echo '<button id="fsr-oh-submit" type="submit" class="button button-primary">';
        echo 'Termin absagen';
        echo '</button>';
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                const select = document.getElementById('fsr_oh_rule_selector');
                const button = document.getElementById('fsr-oh-submit');
                function updateButton(){
                    const cancelled =
                        select.options[select.selectedIndex]
                            .dataset.cancelled === '1';
                    button.textContent = cancelled
                        ? 'Termin wieder zusagen'
                        : 'Termin absagen';
                }
                updateButton();
                select.addEventListener('change', updateButton);
            });
        </script>
        <?php
        echo '</form>';
    }

    return ob_get_clean();
}