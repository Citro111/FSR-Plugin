<?php

if (!defined('ABSPATH')) {
    exit;
}

function fsr_etit_office_hours_portal_shortcode($atts): string {
    if (!current_user_can(fsr_etit_office_hours_manage_capability())) {
        return '<div class="fsr-office-hours-portal-denied">Für die Verwaltung der Sprechstunden fehlen dir die erforderlichen Rechte.</div>';
    }

    $atts = shortcode_atts(['limit' => 20], $atts, 'fsr_office_hours_portal');
    $limit = max(1, min(100, absint($atts['limit'])));
    [$ok, $message] = fsr_etit_office_hours_get_notice();

    $selected_member = fsr_etit_office_hours_get_selected_member();
    $selected_member_id = (int) ($selected_member['id'] ?? 0);
    $selected_member_name = trim((string) ($selected_member['first_name'] ?? '') . ' ' . (string) ($selected_member['last_name'] ?? ''));
    $settings = fsr_etit_office_hours_get_settings();
    $rules = $settings['rules'];
    $cancellations = $settings['cancellations'];
    $allowed_members = fsr_etit_office_hours_get_allowed_members();
    $occurrences = fsr_etit_office_hours_collect_occurrences(
        $rules,
        $limit,
        false,
        $cancellations,
        array_map(static fn(array $member): int => (int) $member['id'], $allowed_members)
    );
    $selected_rules = array_values(array_filter(
        $rules,
        static fn($rule): bool => in_array($selected_member_id, (array) ($rule['member_ids'] ?? []), true)
    ));
    $weekdays = [
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
        5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag',
    ];
    $portal_return_path = fsr_etit_office_hours_current_request_path();

    ob_start();
    ?>
    <div class="fsr-office-hours-portal">
        <p><strong>Geschützter Verwaltungsbereich:</strong> Änderungen sind nur mit der konfigurierten WordPress-Berechtigung möglich.</p>

        <form method="get" style="margin-bottom:16px;">
            <label for="fsr-oh-member"><strong>Mitglied auswählen</strong></label><br>
            <select id="fsr-oh-member" name="member" onchange="this.form.submit()" style="min-width:320px;">
                <?php foreach ($allowed_members as $member) : ?>
                    <option value="<?php echo esc_attr($member['id']); ?>" <?php selected($selected_member_id, (int) $member['id']); ?>>
                        <?php echo esc_html($member['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="button">Anzeigen</button></noscript>
        </form>

        <?php if (!$selected_member_id) : ?>
            <p>Es wurde kein zulässiges Mitglied gefunden.</p>
            </div>
            <?php return (string) ob_get_clean(); ?>
        <?php endif; ?>

        <div class="notice notice-info" style="margin-bottom:16px;padding:12px;">
            Auswahl: <strong><?php echo esc_html($selected_member_name ?: 'Mitglied'); ?></strong>
        </div>
        <?php if ($message !== '') : ?>
            <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="margin-bottom:16px;padding:12px;">
                <?php echo esc_html($message); ?>
            </div>
        <?php endif; ?>

        <details style="margin-bottom:16px;padding:12px;border:1px solid #ddd;background:#fff;">
            <summary><strong>Neue Sprechstunde anlegen</strong></summary>
            <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin-top:16px;">
                <?php wp_nonce_field('fsr_oh_create_rule_submit', '_fsr_oh_create_nonce'); ?>
                <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                <input type="hidden" name="fsr_oh_create_rule_submit" value="1">
                <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                <p><label><strong>Titel</strong><br><input type="text" name="title" class="regular-text" value="Sprechstunde" required></label></p>
                <p>
                    <label><strong>Wochentag</strong><br>
                        <select name="weekday">
                            <?php foreach ($weekdays as $number => $label) : ?>
                                <option value="<?php echo esc_attr($number); ?>" <?php selected($number, 3); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Alle <input type="number" name="week_interval" min="1" max="8" value="1" style="width:70px;"> Wochen</label>
                </p>
                <p><label><strong>Erster Termin</strong><br><input type="date" name="start_date" value="<?php echo esc_attr(wp_date('Y-m-d', time(), wp_timezone())); ?>" required></label></p>
                <p><label><strong>Zeit</strong><br><input type="time" name="start_time" value="10:00" required> bis <input type="time" name="end_time" value="12:00" required></label></p>
                <p><label><strong>Ort</strong><br><input type="text" name="location" class="regular-text" value="FSR-Büro"></label></p>
                <p><label><strong>Notiz</strong><br><input type="text" name="notes" class="regular-text"></label></p>
                <button type="submit" class="button button-primary">Speichern</button>
            </form>
        </details>

        <details style="margin-bottom:16px;padding:12px;border:1px solid #ddd;background:#fff;">
            <summary><strong>Einer vorhandenen Sprechstunde beitreten</strong></summary>
            <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin-top:16px;">
                <?php wp_nonce_field('fsr_oh_join_submit', '_fsr_oh_join_nonce'); ?>
                <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                <input type="hidden" name="fsr_oh_join_submit" value="1">
                <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                <select name="rule_id" required>
                    <option value="">– Bitte wählen –</option>
                    <?php foreach ($rules as $rule) : ?>
                        <option value="<?php echo esc_attr($rule['id']); ?>"><?php echo esc_html($rule['title'] . ' · ' . $rule['location']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Teilnehmen</button>
            </form>
        </details>

        <h3>Verknüpfte Sprechstunden</h3>
        <?php if (!$selected_rules) : ?>
            <p>Für dieses Mitglied sind noch keine Sprechstunden hinterlegt.</p>
        <?php else : ?>
            <?php foreach ($selected_rules as $rule) : ?>
                <details style="margin-bottom:12px;padding:12px;border:1px solid #ddd;background:#fff;">
                    <summary><strong><?php echo esc_html($rule['title']); ?></strong> · <?php echo esc_html(fsr_etit_office_hours_describe_rule($rule)); ?></summary>
                    <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin-top:16px;">
                        <?php wp_nonce_field('fsr_oh_edit_rule_submit', '_fsr_oh_edit_nonce'); ?>
                        <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                        <input type="hidden" name="fsr_oh_edit_rule_submit" value="1">
                        <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                        <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>">
                        <p><label>Titel<br><input type="text" name="title" class="regular-text" value="<?php echo esc_attr($rule['title']); ?>" required></label></p>
                        <p><label>Ort<br><input type="text" name="location" class="regular-text" value="<?php echo esc_attr($rule['location']); ?>"></label></p>
                        <p>
                            <label>Wochentag<br>
                                <select name="weekday">
                                    <?php foreach ($weekdays as $number => $label) : ?>
                                        <option value="<?php echo esc_attr($number); ?>" <?php selected((int) $rule['weekday'], $number); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </p>
                        <p>
                            <label>Wochenintervall <input type="number" name="week_interval" min="1" max="8" value="<?php echo esc_attr($rule['week_interval']); ?>"></label>
                        </p>
                        <p><label>Erster Termin<br><input type="date" name="start_date" value="<?php echo esc_attr($rule['start_date']); ?>" required></label></p>
                        <p><label>Zeit<br><input type="time" name="start_time" value="<?php echo esc_attr($rule['start_time']); ?>" required> bis <input type="time" name="end_time" value="<?php echo esc_attr($rule['end_time']); ?>" required></label></p>
                        <p><label>Notiz<br><input type="text" name="notes" class="regular-text" value="<?php echo esc_attr($rule['notes']); ?>"></label></p>
                        <p><strong>Teilnehmende:</strong> <?php echo esc_html(implode(', ', fsr_etit_office_hours_get_rule_members($rule))); ?></p>
                        <button type="submit" class="button button-primary">Änderungen speichern</button>
                    </form>
                    <div class="fsr-oh-rule-actions" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin:0;">
                            <?php wp_nonce_field('fsr_oh_delete_rule_submit', '_fsr_oh_delete_nonce'); ?>
                            <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                            <input type="hidden" name="fsr_oh_delete_rule_submit" value="1">
                            <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>">
                            <button type="submit" class="button button-link-delete" onclick="return confirm('Diese Sprechstunde wirklich löschen?');">Sprechstunde löschen</button>
                        </form>
                        <?php if (count(fsr_etit_office_hours_normalize_member_ids($rule['member_ids'] ?? [])) > 1) : ?>
                            <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin:0;">
                                <?php wp_nonce_field('fsr_oh_leave_rule_submit', '_fsr_oh_leave_nonce'); ?>
                                <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                                <input type="hidden" name="fsr_oh_leave_rule_submit" value="1">
                                <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>">
                                <button type="submit" class="button" onclick="return confirm('Diese Sprechstunde wirklich verlassen?');">Sprechstunde verlassen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3>Nächste Termine</h3>
        <?php
        $selected_occurrences = array_values(array_filter(
            $occurrences,
            static fn($occurrence): bool => in_array($selected_member_id, $occurrence['member_ids'], true)
        ));
        ?>
        <?php if (!$selected_occurrences) : ?>
            <p>Keine kommenden Termine gefunden.</p>
        <?php else : ?>
            <?php foreach ($selected_occurrences as $occurrence) :
                $is_cancelled = fsr_etit_office_hours_member_is_cancelled(
                    $cancellations,
                    $occurrence['rule_id'],
                    $occurrence['date'],
                    $selected_member_id
                );
                $date = fsr_etit_office_hours_date_object($occurrence['date']);
                ?>
                <div style="margin-bottom:12px;padding:12px;border:1px solid #ddd;background:#fff;">
                    <strong><?php echo esc_html(wp_date('d.m.Y', $date->getTimestamp(), wp_timezone()) . ' · ' . $occurrence['title']); ?></strong><br>
                    <?php echo esc_html($occurrence['start_time'] . '–' . $occurrence['end_time'] . ' · ' . $occurrence['location']); ?>
                    <form method="post" action="<?php echo esc_url($portal_return_path); ?>" style="margin-top:10px;">
                        <?php wp_nonce_field('fsr_oh_cancellation_submit', '_fsr_oh_cancel_nonce'); ?>
                        <input type="hidden" name="_fsr_oh_return_path" value="<?php echo esc_attr($portal_return_path); ?>">
                        <input type="hidden" name="fsr_oh_cancellation_submit" value="1">
                        <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>">
                        <input type="hidden" name="rule_id" value="<?php echo esc_attr($occurrence['rule_id']); ?>">
                        <input type="hidden" name="date" value="<?php echo esc_attr($occurrence['date']); ?>">
                        <?php if ($is_cancelled) : ?>
                            <input type="hidden" name="cancel_action" value="uncancel">
                            <button type="submit" class="button">Wieder zusagen</button>
                        <?php else : ?>
                            <input type="hidden" name="cancel_action" value="cancel">
                            <input type="text" name="reason" placeholder="Optionaler Grund" class="regular-text">
                            <button type="submit" class="button">Absagen</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
