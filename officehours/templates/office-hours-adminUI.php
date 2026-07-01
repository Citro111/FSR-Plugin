<?php

function fsr_office_hours_register_settings() {
    register_setting(
        'fsr_office_hours_settings',
        'fsr_office_hours_settings',
        'fsr_sanitize_office_hours_settings'
    );
}


function fsr_office_hours_render_admin_interface() {
    $settings = fsr_office_hours_get_settings();
    $members_raw = fsr_get_members_data('all')['members'];
    $members_map = [];
    foreach ($members_raw as $m) {
        $members_map[$m['id']] = [
            'first_name' => $m['first_name'] ?? '',
            'last_name' => $m['last_name'] ?? ''
        ];
    }
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
    </div>

    <script>
    jQuery(function($) {

        function initSelect2(scope) {
            scope.find('.fsr-oh-members').select2({
                width: '100%'
            });
        }

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

            const html =
                '<tr class="fsr-oh-row">' +

                '<td>' +
                '<input type="hidden" name="fsr_office_hours_settings[rules][' + index + '][id]" value="rule_new_' + Date.now() + '" />' +
                '<input type="text" name="fsr_office_hours_settings[rules][' + index + '][title]" value="Office Hour" class="regular-text" />' +
                '</td>' +

                '<td>' +
                '<select name="fsr_office_hours_settings[rules][' + index + '][recurrence]" class="fsr-oh-recurrence">' +
                    '<option value="monthly_nth">Monatlich (n-ter Wochentag)</option>' +
                    '<option value="weekly">Wöchentlich</option>' +
                '</select>' +

                '<div class="fsr-oh-monthly-fields">' +
                    '<select name="fsr_office_hours_settings[rules][' + index + '][nth_week]">' +
                        '<option value="1">1.</option>' +
                        '<option value="2">2.</option>' +
                        '<option value="3">3.</option>' +
                        '<option value="4">4.</option>' +
                    '</select>' +
                '</div>' +

                '<div>' +
                    '<select name="fsr_office_hours_settings[rules][' + index + '][weekday]">' +
                        '<option value="1">Montag</option>' +
                        '<option value="2">Dienstag</option>' +
                        '<option value="3" selected>Mittwoch</option>' +
                        '<option value="4">Donnerstag</option>' +
                        '<option value="5">Freitag</option>' +
                        '<option value="6">Samstag</option>' +
                        '<option value="7">Sonntag</option>' +
                    '</select>' +
                '</div>' +

                '<div class="fsr-oh-weekly-fields" style="display:none;">' +
                    'Alle <input type="number" min="1" max="8" name="fsr_office_hours_settings[rules][' + index + '][week_interval]" value="1" style="width:70px" /> Wochen' +
                '</div>' +

                '</td>' +

                '<td>' +
                    '<input type="time" name="fsr_office_hours_settings[rules][' + index + '][start_time]" value="10:00" /> bis ' +
                    '<input type="time" name="fsr_office_hours_settings[rules][' + index + '][end_time]" value="12:00" />' +
                '</td>' +

                '<td>' +
                    '<input type="text" name="fsr_office_hours_settings[rules][' + index + '][location]" value="FSR Büro" class="regular-text" />' +
                '</td>' +

                '<td>' +
                    '<select name="fsr_office_hours_settings[rules][' + index + '][member_ids][]" multiple size="6" class="fsr-oh-members">' +
                        optionsHtml +
                    '</select>' +
                '</td>' +

                '<td>' +
                    '<button type="button" class="button button-link-delete fsr-oh-remove-row">Löschen</button>' +
                '</td>' +

                '</tr>';

            $('#fsr-oh-rules-body').append(html);

            const newRow = $('#fsr-oh-rules-body .fsr-oh-row:last');

            reindexRows();
            $(document).on('change', '.fsr-oh-recurrence', function() {
                const row = $(this).closest('.fsr-oh-row');
                const value = $(this).val();

                row.find('.fsr-oh-monthly-fields').toggle(value === 'monthly_nth');
                row.find('.fsr-oh-weekly-fields').toggle(value === 'weekly');
            });
            function initSelect2(scope) {
                if (!$.fn.select2) return;

                scope.find('.fsr-oh-members').each(function () {
                    if (!$(this).data('select2')) {
                        $(this).select2({ width: '100%' });
                    }
                });
            }
            initSelect2($(document));
        });

        $(document).on('click', '.fsr-oh-remove-row', function() {
            $(this).closest('.fsr-oh-row').remove();
            reindexRows();
        });

    });
    </script>
    <?php
}
