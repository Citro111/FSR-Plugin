<?php
if (!defined('ABSPATH')) exit;

add_action('admin_init', 'fsr_office_hours_register_settings');
add_shortcode('fsr_office_hours', 'fsr_office_hours_shortcode');
add_shortcode('fsr_office_hours_sick', 'fsr_office_hours_sick_shortcode');

function fsr_office_hours_register_settings() {
    register_setting('fsr_office_hours_settings', 'fsr_office_hours_settings', 'fsr_sanitize_office_hours_settings');
}

function fsr_office_hours_get_settings() {
    return wp_parse_args(get_option('fsr_office_hours_settings', []), [
        'sick_form_page' => '',
        'rules' => [],
        'cancellations' => [],
    ]);
}

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

function fsr_office_hours_get_members_map() {
    $data = fsr_get_members_data('all');
    $members = $data['members'] ?? [];
    $map = [];

    foreach ($members as $member) {
        $id = absint($member['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $full_name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $map[$id] = [
            'id' => $id,
            'name' => $full_name !== '' ? $full_name : ('Mitglied #' . $id),
        ];
    }

    return $map;
}

function fsr_office_hours_sanitize_time($time) {
    $time = trim((string) $time);
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : '10:00';
}

function fsr_office_hours_sanitize_rule($rule, $index = 0) {
    $rule = is_array($rule) ? $rule : [];

    $id = sanitize_key((string) ($rule['id'] ?? ''));
    if ($id === '') {
        $id = 'rule_' . ($index + 1) . '_' . wp_generate_password(6, false, false);
    }

    $recurrence = sanitize_key((string) ($rule['recurrence'] ?? 'monthly_nth'));
    if (!in_array($recurrence, ['monthly_nth', 'weekly'], true)) {
        $recurrence = 'monthly_nth';
    }

    $member_ids = [];
    $incoming_ids = $rule['member_ids'] ?? [];
    if (!is_array($incoming_ids)) {
        $incoming_ids = [$incoming_ids];
    }
    foreach ($incoming_ids as $id_value) {
        $clean_id = absint($id_value);
        if ($clean_id > 0) {
            $member_ids[] = $clean_id;
        }
    }

    return [
        'id' => $id,
        'title' => sanitize_text_field((string) ($rule['title'] ?? 'Office Hour')),
        'recurrence' => $recurrence,
        'nth_week' => max(1, min(5, absint($rule['nth_week'] ?? 1))),
        'weekday' => max(1, min(7, absint($rule['weekday'] ?? 3))),
        'week_interval' => max(1, min(8, absint($rule['week_interval'] ?? 1))),
        'start_time' => fsr_office_hours_sanitize_time($rule['start_time'] ?? '10:00'),
        'end_time' => fsr_office_hours_sanitize_time($rule['end_time'] ?? '12:00'),
        'location' => sanitize_text_field((string) ($rule['location'] ?? 'FSR Büro')),
        'member_ids' => array_values(array_unique($member_ids)),
    ];
}

function fsr_sanitize_office_hours_settings($input) {
    $clean = [
        'sick_form_page' => esc_url_raw((string) ($input['sick_form_page'] ?? '')),
        'rules' => [],
        'cancellations' => [],
    ];

    $rules = $input['rules'] ?? [];
    if (is_array($rules)) {
        foreach (array_values($rules) as $index => $rule) {
            $clean_rule = fsr_office_hours_sanitize_rule($rule, $index);
            if (empty($clean_rule['member_ids'])) {
                continue;
            }
            $clean['rules'][] = $clean_rule;
        }
    }

    $cancellations = $input['cancellations'] ?? [];
    if (is_array($cancellations)) {
        foreach ($cancellations as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rule_id = sanitize_key((string) ($item['rule_id'] ?? ''));
            $member_id = absint($item['member_id'] ?? 0);
            $date = sanitize_text_field((string) ($item['occurrence_date'] ?? ''));
            if ($rule_id === '' || $member_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $clean['cancellations'][] = [
                'rule_id' => $rule_id,
                'member_id' => $member_id,
                'occurrence_date' => $date,
                'reason' => sanitize_text_field((string) ($item['reason'] ?? '')),
                'created_at' => sanitize_text_field((string) ($item['created_at'] ?? current_time('mysql'))),
            ];
        }
    }

    return $clean;
}

function fsr_office_hours_nth_weekday_date($year, $month, $weekday, $nth) {
    $first_day = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $first_ts = strtotime($first_day);
    if ($first_ts === false) {
        return null;
    }

    $first_weekday = (int) date('N', $first_ts);
    $delta = ($weekday - $first_weekday + 7) % 7;
    $day = 1 + $delta + (($nth - 1) * 7);

    $candidate = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    $candidate_ts = strtotime($candidate);
    if ($candidate_ts === false || (int) date('n', $candidate_ts) !== (int) $month) {
        return null;
    }

    return $candidate;
}

function fsr_office_hours_collect_occurrences($rules, $limit = 12) {
    $today = current_time('Y-m-d');
    $today_ts = strtotime($today);
    $bucket = [];

    foreach ($rules as $rule) {
        $rule = fsr_office_hours_sanitize_rule($rule);

        if ($rule['recurrence'] === 'monthly_nth') {
            for ($offset = 0; $offset < 12; $offset++) {
                $month_ts = strtotime('first day of +' . $offset . ' month', $today_ts);
                $year = (int) date('Y', $month_ts);
                $month = (int) date('n', $month_ts);
                $date = fsr_office_hours_nth_weekday_date($year, $month, $rule['weekday'], $rule['nth_week']);
                if (!$date || $date < $today) {
                    continue;
                }

                $bucket[] = [
                    'rule_id' => $rule['id'],
                    'date' => $date,
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'title' => $rule['title'],
                    'location' => $rule['location'],
                    'member_ids' => $rule['member_ids'],
                ];
            }
        } else {
            $weekday = (int) $rule['weekday'];
            $week_interval = max(1, (int) $rule['week_interval']);
            $cursor = $today_ts;
            $produced = 0;

            while ($produced < 16) {
                $current_weekday = (int) date('N', $cursor);
                $delta = ($weekday - $current_weekday + 7) % 7;
                $candidate_ts = strtotime('+' . $delta . ' day', $cursor);
                $date = date('Y-m-d', $candidate_ts);

                if ($date >= $today) {
                    $bucket[] = [
                        'rule_id' => $rule['id'],
                        'date' => $date,
                        'start_time' => $rule['start_time'],
                        'end_time' => $rule['end_time'],
                        'title' => $rule['title'],
                        'location' => $rule['location'],
                        'member_ids' => $rule['member_ids'],
                    ];
                    $produced++;
                }

                $cursor = strtotime('+' . $week_interval . ' week', $candidate_ts);
            }
        }
    }

    usort($bucket, static function ($a, $b) {
        $left = $a['date'] . ' ' . $a['start_time'];
        $right = $b['date'] . ' ' . $b['start_time'];
        return strcmp($left, $right);
    });

    return array_slice($bucket, 0, max(1, absint($limit)));
}

function fsr_office_hours_is_member_cancelled($settings, $rule_id, $member_id, $date) {
    foreach ($settings['cancellations'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['rule_id'] ?? '') === $rule_id && absint($item['member_id'] ?? 0) === absint($member_id) && ($item['occurrence_date'] ?? '') === $date) {
            return true;
        }
    }
    return false;
}

function fsr_office_hours_render_admin_interface() {
    $settings = fsr_office_hours_get_settings();
    $members_map = fsr_office_hours_get_members_map();
    $weekday_labels = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
    ?>
    <div class="fsr-oh-admin">
        <h3>Office Hours Regeln</h3>
        <p>Lege wiederkehrende Termine an. Jede Regel hat feste Mitglieder. Ehemalige und aktive Helfer/Gewählte können gemeinsam in einer Regel stehen.</p>

        <table class="widefat striped fsr-oh-rules-table">
            <thead>
            <tr>
                <th>Titel</th>
                <th>Rhythmus</th>
                <th>Zeit</th>
                <th>Ort</th>
                <th>Mitglieder</th>
                <th></th>
            </tr>
            </thead>
            <tbody id="fsr-oh-rules-body">
            <?php foreach (array_values($settings['rules']) as $index => $rule) :
                $rule = fsr_office_hours_sanitize_rule($rule, $index);
                ?>
                <tr class="fsr-oh-row">
                    <td>
                        <input type="hidden" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][id]" value="<?php echo esc_attr($rule['id']); ?>" />
                        <input type="text" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][title]" value="<?php echo esc_attr($rule['title']); ?>" class="regular-text" />
                    </td>
                    <td>
                        <select name="fsr_office_hours_settings[rules][<?php echo $index; ?>][recurrence]" class="fsr-oh-recurrence">
                            <option value="monthly_nth" <?php selected($rule['recurrence'], 'monthly_nth'); ?>>Monatlich (n-ter Wochentag)</option>
                            <option value="weekly" <?php selected($rule['recurrence'], 'weekly'); ?>>Wöchentlich</option>
                        </select>
                        <div class="fsr-oh-monthly-fields" <?php echo $rule['recurrence'] === 'monthly_nth' ? '' : 'style="display:none"'; ?>>
                            <select name="fsr_office_hours_settings[rules][<?php echo $index; ?>][nth_week]">
                                <option value="1" <?php selected($rule['nth_week'], 1); ?>>1.</option>
                                <option value="2" <?php selected($rule['nth_week'], 2); ?>>2.</option>
                                <option value="3" <?php selected($rule['nth_week'], 3); ?>>3.</option>
                                <option value="4" <?php selected($rule['nth_week'], 4); ?>>4.</option>
                                <option value="5" <?php selected($rule['nth_week'], 5); ?>>5.</option>
                            </select>
                        </div>
                        <div>
                            <select name="fsr_office_hours_settings[rules][<?php echo $index; ?>][weekday]">
                                <?php foreach ($weekday_labels as $weekday_key => $weekday_label) : ?>
                                    <option value="<?php echo esc_attr($weekday_key); ?>" <?php selected($rule['weekday'], $weekday_key); ?>><?php echo esc_html($weekday_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fsr-oh-weekly-fields" <?php echo $rule['recurrence'] === 'weekly' ? '' : 'style="display:none"'; ?>>
                            Alle
                            <input type="number" min="1" max="8" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][week_interval]" value="<?php echo esc_attr($rule['week_interval']); ?>" style="width:70px" />
                            Wochen
                        </div>
                    </td>
                    <td>
                        <input type="time" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][start_time]" value="<?php echo esc_attr($rule['start_time']); ?>" />
                        bis
                        <input type="time" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][end_time]" value="<?php echo esc_attr($rule['end_time']); ?>" />
                    </td>
                    <td>
                        <input type="text" name="fsr_office_hours_settings[rules][<?php echo $index; ?>][location]" value="<?php echo esc_attr($rule['location']); ?>" class="regular-text" />
                    </td>
                    <td>
                        <select name="fsr_office_hours_settings[rules][<?php echo $index; ?>][member_ids][]" multiple size="6" class="fsr-oh-members">
                            <?php foreach ($members_map as $member_id => $member_info) : ?>
                                <option value="<?php echo esc_attr($member_id); ?>" <?php selected(in_array($member_id, $rule['member_ids'], true)); ?>><?php echo esc_html($member_info['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="button button-link-delete fsr-oh-remove-row">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" class="button" id="fsr-oh-add-row">Office Hour hinzufügen</button>
        </p>

        <h3>Krankmeldung (Member Self-Service)</h3>
        <p>Erstelle eine Seite mit dem Shortcode <code>[fsr_office_hours_sick]</code> und trage hier die URL ein. Danach kannst du den Mitgliedern ihren persönlichen Link schicken.</p>
        <input type="url" name="fsr_office_hours_settings[sick_form_page]" value="<?php echo esc_attr($settings['sick_form_page']); ?>" class="regular-text" placeholder="https://deine-seite.de/office-hours-krank/" />

        <?php if (!empty($settings['sick_form_page'])) : ?>
            <table class="widefat striped" style="margin-top:12px; max-width:860px;">
                <thead>
                <tr>
                    <th>Mitglied</th>
                    <th>Persönlicher Krankmelden-Link</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($members_map as $member_id => $member_info) :
                    $link = add_query_arg([
                        'fsr_oh_member' => $member_id,
                        'fsr_oh_token' => fsr_office_hours_member_token($member_id),
                    ], $settings['sick_form_page']);
                    ?>
                    <tr>
                        <td><?php echo esc_html($member_info['name']); ?></td>
                        <td><code><?php echo esc_html($link); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <input type="hidden" name="fsr_office_hours_settings[cancellations]" value="<?php echo esc_attr(wp_json_encode($settings['cancellations'])); ?>" />
    </div>

    <script>
    jQuery(function($) {
        function bindRecurrenceToggle(scope) {
            scope.find('.fsr-oh-recurrence').on('change', function() {
                const row = $(this).closest('.fsr-oh-row');
                const value = $(this).val();
                row.find('.fsr-oh-monthly-fields').toggle(value === 'monthly_nth');
                row.find('.fsr-oh-weekly-fields').toggle(value === 'weekly');
            });
        }

        function reindexRows() {
            $('#fsr-oh-rules-body .fsr-oh-row').each(function(index, row) {
                $(row).find('input, select').each(function() {
                    const name = $(this).attr('name');
                    if (!name) return;
                    $(this).attr('name', name.replace(/\[rules\]\[\d+\]/, '[rules][' + index + ']'));
                });
            });
        }

        bindRecurrenceToggle($(document));

        $('#fsr-oh-add-row').on('click', function() {
            const index = $('#fsr-oh-rules-body .fsr-oh-row').length;
            const memberOptions = <?php echo wp_json_encode(array_map(static function ($member) {
                return [
                    'id' => $member['id'],
                    'name' => $member['name'],
                ];
            }, array_values($members_map))); ?>;

            let optionsHtml = '';
            memberOptions.forEach(function(member) {
                optionsHtml += '<option value="' + member.id + '">' + member.name + '</option>';
            });

            const html = '<tr class="fsr-oh-row">'
                + '<td><input type="hidden" name="fsr_office_hours_settings[rules][' + index + '][id]" value="rule_new_' + Date.now() + '" /><input type="text" name="fsr_office_hours_settings[rules][' + index + '][title]" value="Office Hour" class="regular-text" /></td>'
                + '<td><select name="fsr_office_hours_settings[rules][' + index + '][recurrence]" class="fsr-oh-recurrence"><option value="monthly_nth">Monatlich (n-ter Wochentag)</option><option value="weekly">Wöchentlich</option></select>'
                + '<div class="fsr-oh-monthly-fields"><select name="fsr_office_hours_settings[rules][' + index + '][nth_week]"><option value="1">1.</option><option value="2">2.</option><option value="3">3.</option><option value="4">4.</option><option value="5">5.</option></select></div>'
                + '<div><select name="fsr_office_hours_settings[rules][' + index + '][weekday]"><option value="1">Montag</option><option value="2">Dienstag</option><option value="3" selected>Mittwoch</option><option value="4">Donnerstag</option><option value="5">Freitag</option><option value="6">Samstag</option><option value="7">Sonntag</option></select></div>'
                + '<div class="fsr-oh-weekly-fields" style="display:none;">Alle <input type="number" min="1" max="8" name="fsr_office_hours_settings[rules][' + index + '][week_interval]" value="1" style="width:70px" /> Wochen</div></td>'
                + '<td><input type="time" name="fsr_office_hours_settings[rules][' + index + '][start_time]" value="10:00" /> bis <input type="time" name="fsr_office_hours_settings[rules][' + index + '][end_time]" value="12:00" /></td>'
                + '<td><input type="text" name="fsr_office_hours_settings[rules][' + index + '][location]" value="FSR Büro" class="regular-text" /></td>'
                + '<td><select name="fsr_office_hours_settings[rules][' + index + '][member_ids][]" multiple size="6" class="fsr-oh-members">' + optionsHtml + '</select></td>'
                + '<td><button type="button" class="button button-link-delete fsr-oh-remove-row">Löschen</button></td>'
                + '</tr>';

            $('#fsr-oh-rules-body').append(html);
            reindexRows();
            bindRecurrenceToggle($('#fsr-oh-rules-body .fsr-oh-row:last'));
        });

        $(document).on('click', '.fsr-oh-remove-row', function() {
            $(this).closest('.fsr-oh-row').remove();
            reindexRows();
        });
    });
    </script>
    <?php
}

function fsr_office_hours_shortcode($atts) {
    $atts = shortcode_atts(['limit' => 10], $atts);
    $settings = fsr_office_hours_get_settings();
    $members_map = fsr_office_hours_get_members_map();
    $occurrences = fsr_office_hours_collect_occurrences($settings['rules'], absint($atts['limit']));

    if (empty($occurrences)) {
        return '<div class="fsr-office-hours-empty">Aktuell sind keine Office Hours hinterlegt.</div>';
    }

    ob_start();
    echo '<div class="fsr-office-hours-list">';
    foreach ($occurrences as $occurrence) {
        $active_members = [];
        foreach ($occurrence['member_ids'] as $member_id) {
            if (!isset($members_map[$member_id])) {
                continue;
            }
            if (fsr_office_hours_is_member_cancelled($settings, $occurrence['rule_id'], $member_id, $occurrence['date'])) {
                continue;
            }
            $active_members[] = $members_map[$member_id]['name'];
        }

        $date_label = date_i18n('d.m.Y', strtotime($occurrence['date']));
        echo '<article class="fsr-office-hours-item">';
        echo '<h4>' . esc_html($occurrence['title']) . '</h4>';
        echo '<p><strong>Wann:</strong> ' . esc_html($date_label . ', ' . $occurrence['start_time'] . ' - ' . $occurrence['end_time']) . '</p>';
        if (!empty($occurrence['location'])) {
            echo '<p><strong>Wo:</strong> ' . esc_html($occurrence['location']) . '</p>';
        }
        echo '<p><strong>Wer ist drin:</strong> ' . (!empty($active_members) ? esc_html(implode(', ', $active_members)) : 'Noch niemand verfügbar') . '</p>';
        echo '</article>';
    }
    echo '</div>';

    return ob_get_clean();
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

    $rule_id = sanitize_key((string) ($_POST['rule_id'] ?? ''));
    $date = sanitize_text_field((string) ($_POST['occurrence_date'] ?? ''));
    $reason = sanitize_text_field((string) ($_POST['reason'] ?? ''));

    if ($rule_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [false, 'Bitte einen gültigen Termin auswählen.'];
    }

    foreach ($settings['cancellations'] as $entry) {
        if (($entry['rule_id'] ?? '') === $rule_id && absint($entry['member_id'] ?? 0) === $member_id && ($entry['occurrence_date'] ?? '') === $date) {
            return [true, 'Dieser Termin ist bereits als abgesagt markiert.'];
        }
    }

    $settings['cancellations'][] = [
        'rule_id' => $rule_id,
        'member_id' => $member_id,
        'occurrence_date' => $date,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
    ];

    update_option('fsr_office_hours_settings', fsr_sanitize_office_hours_settings($settings));
    return [true, 'Deine Krankmeldung wurde gespeichert.'];
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
        if (fsr_office_hours_is_member_cancelled($settings, $occurrence['rule_id'], $member_id, $occurrence['date'])) {
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
    echo '<select name="rule_id" id="fsr_oh_rule_selector">';
    foreach ($choices as $idx => $choice) {
        $label = date_i18n('d.m.Y', strtotime($choice['date'])) . ' - ' . $choice['title'] . ' (' . $choice['start_time'] . '-' . $choice['end_time'] . ')';
        echo '<option value="' . esc_attr($choice['rule_id']) . '" data-date="' . esc_attr($choice['date']) . '" ' . selected($idx === 0, true, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    $default_date = $choices[0]['date'];
    echo '<input type="hidden" name="occurrence_date" id="fsr_oh_occurrence_date" value="' . esc_attr($default_date) . '" />';

    echo '<p><label>Optionaler Grund:</label><br><input type="text" name="reason" class="regular-text" /></p>';
    echo '<button type="submit" class="button button-primary">Termin als krank melden</button>';
    echo '</form>';

    echo '</div>';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const selector = document.getElementById('fsr_oh_rule_selector');
        const dateField = document.getElementById('fsr_oh_occurrence_date');
        if (!selector || !dateField) return;

        selector.addEventListener('change', function() {
            const selected = selector.options[selector.selectedIndex];
            dateField.value = selected.getAttribute('data-date') || '';
        });
    });
    </script>
    <?php

    return ob_get_clean();
}
