<?php
if (!defined('ABSPATH')) exit;
$members = $members ?? [];

$all_ameter = [];
foreach ($members as $m) {
    if (!empty($m['amt'])) {
        $parts = array_map('trim', explode(',', $m['amt']));
        $all_ameter = array_merge($all_ameter, $parts);
    }
}
$unique_amter = array_unique(array_filter($all_ameter));
sort($unique_amter);

$team_labels = [
    'gewaehlte' => 'Gewählte',
    'helfer' => 'Helfer',
    'ehemalige' => 'Ehemalige'
];

$layout_settings = isset($layout_settings) && is_array($layout_settings) ? $layout_settings : [];
$desktop_cols = max(1, min(6, absint($layout_settings['desktop_cols'] ?? 4)));
$tablet_cols = max(1, min($desktop_cols, absint($layout_settings['tablet_cols'] ?? 2)));
$mobile_cols = max(1, min($tablet_cols, absint($layout_settings['mobile_cols'] ?? 1)));

$shortcode_usage = isset($shortcode_usage) && is_array($shortcode_usage) ? $shortcode_usage : [];
$all_amt_tags_json = wp_json_encode(array_values($unique_amter));
?>
<datalist id="fsr-amter-list">
    <?php foreach ($unique_amter as $amt) : ?>
        <option value="<?php echo esc_attr($amt); ?>">
    <?php endforeach; ?>
</datalist>

<div class="fsr-settings-card-wrapper">
    <h3 class="fsr-card-title">
        <span class="dashicons dashicons-groups"></span>
        Mitglieder-Konfiguration
    </h3>

    <div class="fsr-shortcode-info">
        <strong>Shortcodes</strong><br>
        Alle Mitglieder anzeigen: <code>[fsr_members]</code><br>
        Nur ein Team anzeigen: <code>[fsr_members team="gewaehlte"]</code>, <code>[fsr_members team="helfer"]</code>, <code>[fsr_members team="ehemalige"]</code>

        <form method="post" action="options.php" class="fsr-layout-settings-form">
            <?php settings_fields('fsr_membercards_layout_settings'); ?>
            <h4>Responsive Spalten (global für alle [fsr_members]-Shortcodes)</h4>
            <div class="fsr-layout-grid">
                <label>Desktop<br><input type="number" min="1" max="6" name="fsr_membercards_layout[desktop_cols]" value="<?php echo esc_attr($desktop_cols); ?>"></label>
                <label>Tablet<br><input type="number" min="1" max="6" name="fsr_membercards_layout[tablet_cols]" value="<?php echo esc_attr($tablet_cols); ?>"></label>
                <label>Mobil<br><input type="number" min="1" max="6" name="fsr_membercards_layout[mobile_cols]" value="<?php echo esc_attr($mobile_cols); ?>"></label>
            </div>
            <?php submit_button('Layout speichern', 'secondary', 'submit', false); ?>
        </form>

        <?php if (!empty($shortcode_usage)) : ?>
            <div class="fsr-shortcode-usage">
                <h4>Shortcodes aktuell verwendet in</h4>
                <ul>
                    <?php foreach ($shortcode_usage as $usage) : ?>
                        <li>
                            <?php if (!empty($usage['edit_link'])) : ?>
                                <a href="<?php echo esc_url($usage['edit_link']); ?>"><?php echo esc_html($usage['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($usage['title']); ?>
                            <?php endif; ?>
                            (<?php echo esc_html($usage['type']); ?>, <?php echo esc_html($usage['status']); ?>)
                            - <?php echo esc_html(implode(', ', $usage['shortcodes'])); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="description">Hinweis: Erfasst werden Inhalte aus dem eigentlichen Beitragstext. Shortcodes in externen Builder-Daten sind ggf. nicht sichtbar.</p>
            </div>
        <?php else : ?>
            <p class="description">Aktuell keine Verwendung der Plugin-Shortcodes im Beitragstext gefunden.</p>
        <?php endif; ?>

        <p>Jedes Mitglied wird als eigener Datensatz gespeichert. Dadurch sind Import, Sortierung und spätere Pflege robuster.</p>
    </div>

    <div class="fsr-admin-top-bar">
        <button type="button" class="button button-primary" id="add-member-btn">
            <span class="dashicons dashicons-plus" style="font-size:16px; vertical-align:middle; margin-top:-2px;"></span> Mitglied hinzufügen
        </button>
        <div id="fsr-save-indicator">✓ Änderungen gespeichert</div>
    </div>

    <div class="fsr-filter-wrapper">
        <div class="fsr-filter-group">
            <span class="fsr-filter-label">Filter:</span>
            <button type="button" class="button fsr-filter-btn active" data-filter="all">Alle</button>
            <button type="button" class="button fsr-filter-btn" data-filter="gewaehlte">Gewählte</button>
            <button type="button" class="button fsr-filter-btn" data-filter="helfer">Helfer</button>
            <button type="button" class="button fsr-filter-btn" data-filter="ehemalige">Ehemalige</button>
        </div>
        <div class="fsr-action-group">
            <button type="button" class="button" id="fsr-expand-all">Alle auf</button>
            <button type="button" class="button" id="fsr-collapse-all">Alle zu</button>
        </div>
    </div>
    <div id="fsr-sortable-members">
    <?php foreach ($team_labels as $team_key => $team_title): ?>
    <section class="fsr-team-container" data-team="<?php echo esc_attr($team_key); ?>">
        <h3><?php echo esc_html($team_title); ?></h3>
        <div class="fsr-team-sortable">
        <?php foreach ($members as $index => $member) :
            $team = $member['team'] ?? 'gewaehlte';
            // Nur Mitglieder dieses Teams anzeigen
            if ($team !== $team_key) {
                continue;
            }
            $team_classes = [
                'fsr-team-' . $team
            ];
            $full_display_name = trim(
                ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')
            );
            $current_team_label = $team_labels[$team] ?? ucfirst($team);
        ?>
                    <div class="fsr-member-row <?php echo esc_attr(implode(' ', $team_classes)); ?>" data-member-id="<?php echo esc_attr($member['id'] ?? 0); ?>">
                        <div class="fsr-row-header">
                            <div class="fsr-toggle-trigger">
                                <span class="fsr-arrow">▶</span>
                                <span class="fsr-drag-handle">☰</span>
                                <span class="member-display-name"><?php echo esc_html($full_display_name ?: 'Unbenannt'); ?></span>
                                <span class="badge-team-name"><?php echo esc_html($current_team_label); ?></span>
                            </div>
                        </div>
                        <div class="fsr-row-body" style="display:none;">
                            <div class="fsr-grid-inputs">
                                <input type="hidden" class="fsr-member-id" name="fsr_members_settings[members][<?php echo $index; ?>][id]" value="<?php echo esc_attr($member['id'] ?? 0); ?>" />
                                <label class="col-4">Vorname:<br><input type="text" class="fsr-input-firstname" name="fsr_members_settings[members][<?php echo $index; ?>][first_name]" value="<?php echo esc_attr($member['first_name'] ?? ''); ?>" required /></label>
                                <label class="col-4">Nachname:<br><input type="text" class="fsr-input-lastname" name="fsr_members_settings[members][<?php echo $index; ?>][last_name]" value="<?php echo esc_attr($member['last_name'] ?? ''); ?>" required /></label>
                                <label class="col-4">Bild-URL:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][image]" value="<?php echo esc_attr($member['image'] ?? ''); ?>" placeholder="https://..." /></label>

                                <label class="col-4">Studiengang:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][studiengang]" value="<?php echo esc_attr($member['studiengang'] ?? ''); ?>" /></label>
                                <label class="col-2">Abschluss:<br>
                                    <select name="fsr_members_settings[members][<?php echo $index; ?>][abschluss]">
                                        <option value="" <?php selected($member['abschluss'] ?? '', ''); ?>>-</option>
                                        <option value="B.Sc." <?php selected($member['abschluss'] ?? '', 'B.Sc.'); ?>>B.Sc.</option>
                                        <option value="M.Sc." <?php selected($member['abschluss'] ?? '', 'M.Sc.'); ?>>M.Sc.</option>
                                        <option value="Abgeschlossen" <?php selected($member['abschluss'] ?? '', 'Abgeschlossen'); ?>>Abgeschlossen</option>
                                    </select>
                                </label>
                                <label class="col-2">Pronomen:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][pronomen]" value="<?php echo esc_attr($member['pronomen'] ?? ''); ?>" placeholder="er/ihm" /></label>
                                <label class="col-4">Mail-Präfix:<br><input type="text" class="fsr-input-email" name="fsr_members_settings[members][<?php echo $index; ?>][email_prefix]" value="<?php echo esc_attr($member['email_prefix'] ?? ''); ?>" /></label>

                                <label class="col-4">Ämter (kommagetrennt):<br>
                                    <input type="text" list="fsr-amter-list" class="fsr-input-amt" name="fsr_members_settings[members][<?php echo $index; ?>][amt]" value="<?php echo esc_attr($member['amt'] ?? ''); ?>" />
                                    <div class="fsr-amt-quicktags">
                                        <?php foreach ($unique_amter as $amt_tag) : ?>
                                            <button type="button" class="button button-small fsr-amt-tag-btn" data-tag="<?php echo esc_attr($amt_tag); ?>"><?php echo esc_html($amt_tag); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </label>
                                <label class="col-2">Erstes Jahr:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][erstes_jahr]" value="<?php echo esc_attr($member['erstes_jahr'] ?? ''); ?>" /></label>
                                <label class="col-2">Semester:<br><input type="number" name="fsr_members_settings[members][<?php echo $index; ?>][semester_anzahl]" value="<?php echo esc_attr($member['semester_anzahl'] ?? ''); ?>" /></label>
                                <label class="col-4">Team:<br>
                                    <select class="fsr-team-selector" name="fsr_members_settings[members][<?php echo $index; ?>][team]">
                                        <option value="gewaehlte" <?php selected($member['team'] ?? '', 'gewaehlte'); ?>>Gewählte</option>
                                        <option value="helfer" <?php selected($member['team'] ?? '', 'helfer'); ?>>Helfer</option>
                                        <option value="ehemalige" <?php selected($member['team'] ?? '', 'ehemalige'); ?>>Ehemalige</option>
                                    </select>
                                </label>
                                <label class="col-2">Abgegangen im Jahr:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][abgang_jahr]" value="<?php echo esc_attr($member['abgang_jahr'] ?? ''); ?>" placeholder="z. B. 2025" /></label>
                            </div>
                            <div class="fsr-row-footer-actions">
                                <button type="button" class="button button-link-delete remove-member">Dauerhaft löschen</button>
                                <button type="button" class="button duplicate-member"> Kopieren</button>
                            </div>
                        </div>
                    </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    </div>
    <div class="fsr-import-panel">
        <div class="fsr-import-copy">
            <h4>Bulk Import für das Setup</h4>
            <p>Füge hier JSON oder CSV ein, um bestehende Daten schnell anzulegen. Der Import ersetzt auf Wunsch die vorhandenen Mitglieder und legt jeden Eintrag als eigenen Datensatz an.</p>
        </div>
        <textarea id="fsr-member-import-data" rows="10" placeholder='JSON-Beispiel: [{"first_name":"Max","last_name":"Mustermann","team":"gewaehlte"}]\nCSV-Beispiel: first_name,last_name,team'></textarea>
        <div class="fsr-import-tools">
            <label class="fsr-import-file">
                <span>Optional Datei laden</span>
                <input type="file" id="fsr-member-import-file" accept=".json,.csv,.txt,application/json,text/csv,text/plain">
            </label>
            <label class="fsr-import-replace">
                <input type="checkbox" id="fsr-member-import-replace" checked>
                Vorhandene Mitglieder vor dem Import löschen
            </label>
            <button type="button" class="button button-secondary" id="fsr-member-import-btn">Import starten</button>
        </div>
        <div class="fsr-import-hint">
            JSON-Objekte können die Felder <code>first_name</code>, <code>last_name</code>, <code>image</code>, <code>studiengang</code>, <code>abschluss</code>, <code>pronomen</code>, <code>email_prefix</code>, <code>amt</code>, <code>erstes_jahr</code>, <code>semester_anzahl</code>, <code>team</code> und <code>abgang_jahr</code> enthalten.
        </div>
        <div class="fsr-known-tags">
            <strong>Aktuelle Ämter-Tags:</strong>
            <div class="fsr-known-tags-list">
                <?php if (!empty($unique_amter)) : ?>
                    <?php foreach ($unique_amter as $amt_tag) : ?>
                        <span class="fsr-known-tag"><?php echo esc_html($amt_tag); ?></span>
                    <?php endforeach; ?>
                <?php else : ?>
                    <span class="description">Noch keine Tags vorhanden.</span>
                <?php endif; ?>
            </div>
        </div>
        <div id="fsr-import-status" aria-live="polite"></div>
    </div>
</div>

<input type="hidden" id="fsr_member_admin_nonce" value="<?php echo esc_attr(wp_create_nonce('fsr-member-admin-nonce')); ?>">

<script>
jQuery(document).ready(function($) {
    const nonce = $('#fsr_member_admin_nonce').val();
    const allAmtTags = <?php echo $all_amt_tags_json ? $all_amt_tags_json : '[]'; ?>;
    let activeFilter = 'all';

    function slugify(text) {
        return text.toString().toLowerCase()
            .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
            .replace(/[^a-z0-9_.-]/g, '');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function teamLabel(value) {
        const labels = { gewaehlte: 'Gewählte', helfer: 'Helfer', ehemalige: 'Ehemalige' };
        let label = labels[value] || value.charAt(0).toUpperCase() + value.slice(1);
        return label;
    }

    function parseAmtTags(value) {
        return String(value || '')
            .split(',')
            .map(function(item) { return item.trim(); })
            .filter(function(item) { return item !== ''; });
    }

    function setAmtTags(input, tags) {
        const normalized = [];
        tags.forEach(function(tag) {
            const clean = String(tag || '').trim();
            if (clean !== '' && normalized.indexOf(clean) === -1) {
                normalized.push(clean);
            }
        });
        input.val(normalized.join(', '));
    }

    function renderDynamicQuickTags(scope) {
        scope.find('.fsr-amt-quicktags-dynamic').each(function() {
            const container = $(this);
            if (container.data('ready')) {
                return;
            }
            allAmtTags.forEach(function(tag) {
                const button = $('<button/>', {
                    type: 'button',
                    class: 'button button-small fsr-amt-tag-btn',
                    'data-tag': tag,
                    text: tag
                });
                container.append(button);
            });
            container.data('ready', true);
        });
    }

    function applyRowTeamState(row) {
        const val = row.find('.fsr-team-selector').val();

        row.removeClass('fsr-team-gewaehlte fsr-team-helfer fsr-team-ehemalige');
        row.addClass('fsr-team-' + val);
        row.find('.badge-team-name').text(teamLabel(val));
    }

    function filterRows(filter) {
        $('#fsr-sortable-members .fsr-member-row').each(function() {
            const row = $(this);
            const team = row.closest('.fsr-team-container').data('team');
            const match = filter === 'all' || team === filter;
            row.toggleClass('fsr-is-filter-hidden', !match);
        });
    }

    function applyCurrentFilter() {
        filterRows(activeFilter);
    }

    function triggerAutoSave() {
        reindexMemberRows();
        $('#fsr-save-indicator').text('Speichert...').css({'color':'var(--theme-palette-color-6)', 'background':'var(--theme-palette-color-13)', 'border-color':'var(--theme-palette-color-5)'}).fadeIn();
        const formData = $('#fsr-sortable-members :input').serialize();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'fsr_save_member_order', order: formData, nonce: nonce },
            success: function(response) {
                if(response.success) {
                    if (response.data && response.data.member_ids) {
                        $('#fsr-sortable-members .fsr-member-row').each(function(index) {
                            const memberId = response.data.member_ids[index] || '';
                            $(this).find('.fsr-member-id').val(memberId);
                            $(this).attr('data-member-id', memberId);
                        });
                    }
                    $('#fsr-save-indicator').text('✓ Gespeichert').css({'color':'var(--theme-palette-color-6)', 'background':'var(--theme-palette-color-13)', 'border-color':'var(--theme-palette-color-5)'}).delay(1500).fadeOut();
                } else {
                    $('#fsr-save-indicator').text('❌ Fehler').css({'color':'#dc2626', 'background':'#fef2f2', 'border-color':'#fecaca'}).fadeIn();
                }
            }
        });
    }

    function createMemberRow(index) {
        return `
        <div class="fsr-member-row fsr-team-gewaehlte is-expanded" data-member-id="0">
            <div class="fsr-row-header">
                <div class="fsr-toggle-trigger">
                    <span class="fsr-arrow">▶</span>
                    <span class="fsr-drag-handle">☰</span>
                    <span class="member-display-name">Neues Mitglied</span>
                    <span class="badge-team-name">Gewählte</span>
                </div>
            </div>
            <div class="fsr-row-body" style="display:block;">
                <div class="fsr-grid-inputs">
                    <input type="hidden" class="fsr-member-id" name="fsr_members_settings[members][${index}][id]" value="" />
                    <label class="col-4">Vorname:<br><input type="text" class="fsr-input-firstname" name="fsr_members_settings[members][${index}][first_name]" required /></label>
                    <label class="col-4">Nachname:<br><input type="text" class="fsr-input-lastname" name="fsr_members_settings[members][${index}][last_name]" required /></label>
                    <label class="col-4">Bild-URL:<br><input type="text" name="fsr_members_settings[members][${index}][image]" placeholder="https://..." /></label>
                    <label class="col-4">Studiengang:<br><input type="text" name="fsr_members_settings[members][${index}][studiengang]" /></label>
                    <label class="col-2">Abschluss:<br><select name="fsr_members_settings[members][${index}][abschluss]"><option value="">-</option><option value="B.Sc.">B.Sc.</option><option value="M.Sc.">M.Sc.</option><option value="Abgeschlossen">Abgeschlossen</option></select></label>
                    <label class="col-2">Pronomen:<br><input type="text" name="fsr_members_settings[members][${index}][pronomen]" placeholder="er/ihm" /></label>
                    <label class="col-4">Mail-Präfix:<br><input type="text" class="fsr-input-email" name="fsr_members_settings[members][${index}][email_prefix]" /></label>
                    <label class="col-4">Ämter:<br>
                        <input type="text" list="fsr-amter-list" class="fsr-input-amt" name="fsr_members_settings[members][${index}][amt]" />
                        <div class="fsr-amt-quicktags fsr-amt-quicktags-dynamic"></div>
                    </label>
                    <label class="col-2">Erstes Jahr:<br><input type="text" name="fsr_members_settings[members][${index}][erstes_jahr]" /></label>
                    <label class="col-2">Semester:<br><input type="number" name="fsr_members_settings[members][${index}][semester_anzahl]" /></label>
                    <label class="col-4">Team:<br>
                        <select class="fsr-team-selector" name="fsr_members_settings[members][${index}][team]">
                            <option value="gewaehlte">Gewählte</option>
                            <option value="helfer">Helfer</option>
                            <option value="ehemalige">Ehemalige</option>
                        </select>
                    </label>
                    <label class="col-2">Abgegangen im Jahr:<br><input type="text" name="fsr_members_settings[members][${index}][abgang_jahr]" placeholder="z. B. 2025" /></label>
                </div>
                <div class="fsr-row-footer-actions">
                    <button type="button" class="button button-link-delete remove-member">Dauerhaft löschen</button>
                    <button type="button" class="button duplicate-member"> Kopieren</button>
                </div>
            </div>
        </div>`;
    }

    function handleImport(rawData) {
        $('#fsr-import-status').text('Import läuft...').removeClass('is-error is-success').addClass('is-loading');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fsr_import_members',
                nonce: nonce,
                import_data: rawData,
                replace_existing: $('#fsr-member-import-replace').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    $('#fsr-import-status').text(response.data.message || 'Import erfolgreich. Seite wird neu geladen.').removeClass('is-loading is-error').addClass('is-success');
                    window.setTimeout(function() { window.location.reload(); }, 800);
                } else {
                    $('#fsr-import-status').text(response.data || 'Import fehlgeschlagen.').removeClass('is-loading is-success').addClass('is-error');
                }
            },
            error: function() {
                $('#fsr-import-status').text('Import fehlgeschlagen.').removeClass('is-loading is-success').addClass('is-error');
            }
        });
    }

    $('.fsr-team-sortable').sortable({
        receive: function() {
            triggerAutoSave();
        },
        items: '.fsr-member-row',
        handle: '.fsr-drag-handle',
        connectWith: '.fsr-team-sortable',
        placeholder: 'ui-state-highlight',
        forcePlaceholderSize: true,
        update: function() {
            triggerAutoSave();
        }
    });

    $(document).on('click', '.fsr-row-header, .fsr-toggle-trigger', function(e) {
        if ($(e.target).hasClass('fsr-drag-handle')) {
            return;
        }
        const row = $(this).closest('.fsr-member-row');
        const body = row.find('.fsr-row-body');
        body.stop(true,true).slideToggle(200);
        row.toggleClass('is-expanded');
    });

    $('#fsr-expand-all').on('click', function() {
        $('#fsr-sortable-members .fsr-row-body').slideDown(200);
        $('#fsr-sortable-members .fsr-member-row').addClass('is-expanded');
    });
    $('#fsr-collapse-all').on('click', function() {
        $('#fsr-sortable-members .fsr-row-body').slideUp(200);
        $('#fsr-sortable-members .fsr-member-row').removeClass('is-expanded');
    });
    $(document).on('change', '#fsr-sortable-members input, #fsr-sortable-members select', function() { triggerAutoSave(); });

    $(document).on('input', '.fsr-input-firstname, .fsr-input-lastname', function() {
        const row = $(this).closest('.fsr-member-row');
        const fname = row.find('.fsr-input-firstname').val() || '';
        const lname = row.find('.fsr-input-lastname').val() || '';
        row.find('.member-display-name').text((fname + ' ' + lname).trim() || 'Unbenannt');

        const mailField = row.find('.fsr-input-email');
        if($(this).hasClass('fsr-input-firstname') && !mailField.val()) {
            mailField.attr('placeholder', slugify(fname));
        }
    });

    $('.fsr-filter-btn').on('click', function() {
        $('.fsr-filter-btn').removeClass('active');
        $(this).addClass('active');
        activeFilter = $(this).data('filter');
        applyCurrentFilter();
    });

    $(document).on('click', '.fsr-amt-tag-btn', function() {
        const button = $(this);
        const row = button.closest('.fsr-member-row');
        const input = row.find('.fsr-input-amt').first();
        const tag = String(button.data('tag') || '').trim();
        if (!tag || !input.length) {
            return;
        }

        const tags = parseAmtTags(input.val());
        if (tags.indexOf(tag) !== -1) {
            setAmtTags(input, tags.filter(function(item) { return item !== tag; }));
            button.removeClass('is-selected');
        } else {
            tags.push(tag);
            setAmtTags(input, tags);
            button.addClass('is-selected');
        }

        triggerAutoSave();
    });

    $(document).on('input', '.fsr-input-amt', function() {
        const input = $(this);
        const tags = parseAmtTags(input.val());
        input.closest('.fsr-member-row').find('.fsr-amt-tag-btn').each(function() {
            const btn = $(this);
            const tag = String(btn.data('tag') || '').trim();
            btn.toggleClass('is-selected', tags.indexOf(tag) !== -1);
        });
    });

    $(document).on('click', '.remove-member', function() {
        if(confirm('Mitglied wirklich löschen?')) {
            $(this).closest('.fsr-member-row').remove();
            triggerAutoSave();
        }
    });

    $('#add-member-btn').on('click', function() {
        const index = $('#fsr-sortable-members input[name*="[members]"]').length;
        const row = $(createMemberRow(index));
        $('#fsr-sortable-members').prepend(row);
        renderDynamicQuickTags(row);
        applyRowTeamState(row);
        applyCurrentFilter();
        triggerAutoSave();
    });

    $('#fsr-member-import-btn').on('click', function() {
        const fileInput = document.getElementById('fsr-member-import-file');
        const textValue = $('#fsr-member-import-data').val().trim();

        if (fileInput.files && fileInput.files[0]) {
            const reader = new FileReader();
            reader.onload = function(event) {
                handleImport(event.target.result || '');
            };
            reader.readAsText(fileInput.files[0]);
            return;
        }

        if (!textValue) {
            $('#fsr-import-status').text('Bitte JSON oder CSV einfügen oder eine Datei auswählen.').removeClass('is-loading is-success').addClass('is-error');
            return;
        }

        handleImport(textValue);
    });

    function reindexMemberRows() {
        $('#fsr-sortable-members .fsr-member-row').each(function(index, row) {
            $(row).find('input, select').each(function() {
                const nameAttr = $(this).attr('name');
                if(nameAttr) { $(this).attr('name', nameAttr.replace(/\[members\]\[\d+\]/, '[members][' + index + ']')); }
            });
        });
    }

    $(document).on('click','.duplicate-member',function(){
        const row=$(this).closest('.fsr-member-row');
        const clone=row.clone();
        clone.attr('data-member-id','0');
        clone.find('.fsr-member-id').val('');
        clone.find('.member-display-name')
            .text(
                clone.find('.fsr-input-firstname').val()+' Kopie'
            );
        row.after(clone);
        reindexMemberRows();
        triggerAutoSave();
    });
    
    function moveRowToTeam(row) {
        const team = row.find('.fsr-team-selector').val();

        $('.fsr-team-sortable[data-team="'+team+'"]')
            .append(row);
    }
    $(document).on('change','.fsr-team-selector',function(){
        const row=$(this).closest('.fsr-member-row');
        moveRowToTeam(row);
        triggerAutoSave();
    });

    $('#fsr-sortable-members .fsr-member-row').each(function() {
        const row = $(this);
        applyRowTeamState(row);
        const amtInput = row.find('.fsr-input-amt');
        if (amtInput.length) {
            amtInput.trigger('input');
        }
    });

    renderDynamicQuickTags($('#fsr-sortable-members'));
    applyCurrentFilter();
});
</script>
