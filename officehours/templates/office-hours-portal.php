<?php

function fsr_office_hours_portal_shortcode($atts): string {
    $atts = shortcode_atts([
        'limit' => 20,
    ], $atts, 'fsr_office_hours_portal');

    $selected_member = fsr_office_hours_get_selected_member();
    $selected_member_id = $selected_member ? (int) ($selected_member['id'] ?? 0) : 0;
    $selected_member_name = trim((string) ($selected_member['first_name'] ?? ''));
    $selected_member_name = $selected_member_name !== '' ? $selected_member_name : 'Mitglied';

    [$ok, $message] = fsr_office_hours_handle_portal_actions();

    $settings = fsr_office_hours_get_settings();
    $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
    $cancellations = is_array($settings['cancellations'] ?? null) ? $settings['cancellations'] : [];
    $allowed_members = fsr_office_hours_get_allowed_members();
    $occurrences = fsr_office_hours_collect_occurrences($rules, absint($atts['limit']), true);

    $selected_rules = [];
    $edit_rule_id = sanitize_key($_GET['edit_rule'] ?? '');
    $edit_rule = null;
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $rule = fsr_office_hours_sanitize_rule($rule);
        if ($selected_member_id > 0 && in_array($selected_member_id, $rule['member_ids'], true)) {
            $selected_rules[] = $rule;
        }
        if ($edit_rule_id !== '' && ($rule['id'] ?? '') === $edit_rule_id) {
            $edit_rule = $rule;
        }
    }

    ob_start();
    ?>
    <div class="fsr-office-hours-portal" style="display:grid;grid-template-columns:1.1fr .9fr;gap:24px;align-items:start;">
        <div>
            <form method="get" style="margin-bottom: 16px;">
                <label for="fsr_oh_member"><strong>Mitglied auswählen</strong></label><br>
                <select id="fsr_oh_member" name="member" onchange="this.form.submit()" style="min-width: 320px;">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($allowed_members as $member) : ?>
                        <option value="<?php echo esc_attr($member['id']); ?>" <?php selected($selected_member_id, (int) $member['id']); ?>>
                            <?php echo esc_html($member['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button">Anzeigen</button></noscript>
            </form>

            <?php if ($selected_member_id > 0) : ?>
                <div class="notice notice-info" style="margin-bottom:16px;padding:12px;">
                    <strong>Guten Tag, <?php echo esc_html($selected_member_name); ?></strong>.
                    <?php if (!empty($selected_rules)) : ?>
                        <div style="margin-top:8px;">Eigene Sprechstunden: <?php echo esc_html(count($selected_rules)); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== '') : ?>
                <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="margin-bottom:16px;padding:12px;">
                    <?php echo esc_html($message);
                    ?>
                </div>
            <?php endif; ?>
            <details style="margin-bottom: 16px; padding: 12px; border: 1px solid #ddd; background: #fff;">
                <summary style="cursor:pointer; font-weight:600;">Neue Sprechstunde anlegen</summary>
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('fsr_oh_create_rule_submit', '_fsr_oh_create_nonce'); ?>
                    <input type="hidden" name="fsr_oh_create_rule_submit" value="1" />
                    <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />
                    <p>
                        <label><strong>Titel</strong></label><br>
                        <input type="text" name="title" class="regular-text" value="Office Hour" required />
                    </p>
                    <p class="fsr-oh-create-weekly">
                        <label><strong>Wochentag</strong></label><br>
                        <select name="weekday">
                            <option value="1">Montag</option>
                            <option value="2">Dienstag</option>
                            <option value="3" selected>Mittwoch</option>
                            <option value="4">Donnerstag</option>
                            <option value="5">Freitag</option>
                            <option value="6">Samstag</option>
                            <option value="7">Sonntag</option>
                        </select>
                        <br><br>
                        Alle <input type="number" name="week_interval" min="1" max="8" value="1" style="width:72px;" /> Wochen
                    </p>
                    <p>
                        <label><strong>Erster Termin</strong></label><br>
                        <input type="date"
                            name="start_date"
                            value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                    </p>
                    <p>
                        <label><strong>Zeit</strong></label><br>
                        <input type="time" name="start_time" value="10:00" /> bis
                        <input type="time" name="end_time" value="12:00" />
                    </p>
                    <p>
                        <label><strong>Ort</strong></label><br>
                        <input type="text" name="location" class="regular-text" value="FSR Büro" />
                    </p>
                    <p>
                        <label><strong>Notiz</strong></label><br>
                        <input type="text" name="notes" class="regular-text" placeholder="Optional" />
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">Speichern</button>
                    </p>
                </form>
            </details>
            <?php if ($selected_member_id > 0) : ?>
                <details style="margin-bottom: 16px; padding: 12px; border: 1px solid #ddd; background: #fff;">
                    <summary style="cursor:pointer; font-weight:600;">Einer vorhandenen Sprechstunde beitreten</summary>
                    <form method="post" style="margin-top:16px;">
                        <?php wp_nonce_field('fsr_oh_join_submit', '_fsr_oh_join_nonce'); ?>
                        <input type="hidden" name="fsr_oh_join_submit" value="1" />
                        <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />
                        <p>
                            <label><strong>Sprechstunde</strong></label><br>
                            <select name="rule_id" class="fsr-oh-select2" style="min-width:320px;" required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($rules as $rule) : ?>
                                    <?php $rule = fsr_office_hours_sanitize_rule($rule); ?>
                                    <option value="<?php echo esc_attr($rule['id']); ?>">
                                        <?php echo esc_html($rule['title'] . ' · ' . $rule['location']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <button type="submit" class="button">Teilnehmen</button>
                        </p>
                    </form>
                </details>
            <?php endif; ?>

            <h3 style="margin-top:24px;">Meine Sprechstunden</h3>
            <?php if (empty($selected_rules)) : ?>
                <p>Du hast noch keine Sprechstunden hinterlegt.</p>
            <?php else : ?>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($selected_rules as $rule) : ?>
                    <details style="padding:12px;border:1px solid #ddd;background:#fff;">
                        <summary style="cursor:pointer;font-weight:600;">
                            <?php echo esc_html($rule['title']); ?>
                            <span style="font-weight:normal;">
                                · <?php echo esc_html(fsr_office_hours_describe_rule($rule)); ?>
                            </span>
                        </summary>
                        <form method="post" style="margin-top:16px;">
                            <?php wp_nonce_field(
                                'fsr_oh_edit_rule_submit',
                                '_fsr_oh_edit_nonce'
                            ); ?>
                            <input type="hidden" 
                                name="fsr_oh_edit_rule_submit" 
                                value="1">
                            <input type="hidden" 
                                name="member" 
                                value="<?php echo esc_attr($selected_member_id); ?>">
                            <input type="hidden" 
                                name="rule_id" 
                                value="<?php echo esc_attr($rule['id']); ?>">
                            <p>
                                <label><strong>Titel</strong></label><br>
                                <input type="text"
                                    name="title"
                                    class="regular-text"
                                    value="<?php echo esc_attr($rule['title']); ?>">
                            </p>
                            <p>
                                <label><strong>Ort</strong></label><br>
                                <input type="text"
                                    name="location"
                                    class="regular-text"
                                    value="<?php echo esc_attr($rule['location']); ?>">
                            </p>
                            <p>
                                <label><strong>Zeit</strong></label><br>
                                <input type="time"
                                    name="start_time"
                                    value="<?php echo esc_attr($rule['start_time']); ?>">
                                bis
                                <input type="time"
                                    name="end_time"
                                    value="<?php echo esc_attr($rule['end_time']); ?>">
                            </p>
                            <p>
                                <label><strong>Notiz</strong></label><br>
                                <input type="text"
                                    name="notes"
                                    class="regular-text"
                                    value="<?php echo esc_attr($rule['notes']); ?>">
                            </p>
                            <p>
                                <strong>Teilnehmende</strong><br>
                                <?php 
                                echo esc_html(
                                    implode(', ', fsr_office_hours_get_rule_members($rule))
                                );
                                ?>
                            </p>
                            <button type="submit" class="button button-primary">
                                Änderungen speichern
                            </button>
                            <button type="reset"
                                    class="button">
                                Verwerfen
                            </button>
                        </form>
                        <form method="post" style="margin-top:12px;">
                            <?php wp_nonce_field(
                                'fsr_oh_delete_rule_submit',
                                '_fsr_oh_delete_nonce'
                            ); ?>
                            <input type="hidden"
                                name="fsr_oh_delete_rule_submit"
                                value="1">
                            <input type="hidden"
                                name="member"
                                value="<?php echo esc_attr($selected_member_id); ?>">
                            <input type="hidden"
                                name="rule_id"
                                value="<?php echo esc_attr($rule['id']); ?>">
                            <button type="submit"
                                    class="button"
                                    onclick="return confirm('Diese Sprechstunde wirklich löschen?');">
                                Sprechstunde löschen
                            </button>
                        </form>
                    </details>
                    <?php endforeach; ?>
                    </div>
            <?php endif; ?>
            <h3 style="margin-top:24px;">Meine nächsten Termine</h3>
            <?php
            $selected_occurrences = [];
            foreach ($occurrences as $occurrence) {
                if ($selected_member_id <= 0 || !in_array($selected_member_id, $occurrence['member_ids'], true)) {
                    continue;
                }
                $selected_occurrences[] = $occurrence;
            }
            ?>
            <?php if (empty($selected_occurrences)) : ?>
                <p>Keine kommenden Termine gefunden.</p>
            <?php else : ?>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($selected_occurrences as $occurrence) : ?>
                        <?php
                        $is_cancelled = fsr_office_hours_member_is_cancelled(
                            $cancellations,
                            $occurrence['rule_id'],
                            $occurrence['date'],
                            $selected_member_id
                        );
                        $active_member_ids = [];
                        foreach (($occurrence['member_ids'] ?? []) as $member_id) {
                            $member_id = (int) $member_id;
                            if ($member_id <= 0) {
                                continue;
                            }
                            if (fsr_office_hours_member_is_cancelled($cancellations, $occurrence['rule_id'], $occurrence['date'], $member_id)) {
                                continue;
                            }
                            $active_member_ids[] = $member_id;
                        }
                        $active_names = [];
                        foreach ($active_member_ids as $member_id) {
                            $member = fsr_office_hours_get_member_by_id($member_id);
                            if (!$member) {
                                continue;
                            }
                            $name = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
                            if ($name !== '') {
                                $active_names[] = $name;
                            }
                        }
                        $fallback_name = fsr_office_hours_get_rule_members(fsr_office_hours_sanitize_rule($occurrence));
                        $show_names = !empty($active_names) ? $active_names : $fallback_name;
                        $is_empty = empty($show_names);
                        ?>
                        <div style="padding:12px;border:1px solid #ddd;background:#fff;">
                            <strong><?php echo esc_html(date_i18n('d.m.Y', strtotime($occurrence['date'])) . ' · ' . $occurrence['title']); ?></strong><br>
                            <?php echo esc_html($occurrence['start_time'] . '–' . $occurrence['end_time']); ?> · <?php echo esc_html($occurrence['location']); ?><br>
                            <small>
                                <?php if ($is_empty) : ?>
                                    Keine aktiven Teilnehmenden
                                <?php else : ?>
                                    <?php echo esc_html(implode(', ', $show_names)); ?>
                                <?php endif; ?>
                            </small>
                            <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <?php wp_nonce_field('fsr_oh_cancellation_submit', '_fsr_oh_cancel_nonce'); ?>
                                <input type="hidden" name="fsr_oh_cancellation_submit" value="1" />
                                <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($occurrence['rule_id']); ?>" />
                                <input type="hidden" name="date" value="<?php echo esc_attr($occurrence['date']); ?>" />
                                <?php if ($is_cancelled) : ?>
                                    <input type="hidden" name="cancel_action" value="uncancel" />
                                    <button type="submit" class="button">Wieder zusagen</button>
                                <?php else : ?>
                                    <input type="hidden" name="cancel_action" value="toggle" />
                                    <input type="text" name="reason" placeholder="Optionaler Grund" class="regular-text" style="max-width:240px;" />
                                    <button type="submit" class="button">Absagen</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        jQuery(function($) {
            function toggleCreateRecurrence() {
                const mode = $('.fsr-oh-recurrence-create').val();
                $('.fsr-oh-create-weekly').toggle(mode === 'weekly');
                $('.fsr-oh-create-monthly').toggle(mode === 'monthly_nth');
            }

            $('.fsr-oh-recurrence-create').on('change', toggleCreateRecurrence);
            toggleCreateRecurrence();

            if ($.fn.select2) {
                $('.fsr-oh-select2').select2({ width: '100%' });
            }
        });
    </script>
    <?php

    return ob_get_clean();
}