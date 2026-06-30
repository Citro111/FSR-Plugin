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

echo '<datalist id="fsr-amter-list">';
foreach ($unique_amter as $amt) {
    echo '<option value="' . esc_attr($amt) . '">';
}
echo '</datalist>';
?>

<div class="fsr-settings-card-wrapper">
    <!-- Überschrift mit dem gewünschten Einstellungs-Icon -->
    <h3 class="fsr-card-title" style="display:flex; align-items:center; gap:8px; margin-bottom:15px;">
        <span class="dashicons dashicons-admin-generic" style="color: var(--theme-palette-color-4);"></span>
        Mitglieder-Konfiguration
    </h3>

<div class="fsr-shortcode-info">
    <strong>Shortcodes</strong><br>
    Alle Mitglieder anzeigen:
    <code>[fsr_members]</code><br>
    Nur ein Team anzeigen:
    <code>[fsr_members team="gewaehlte"]</code>,
    <code>[fsr_members team="helfer"]</code>,
    <code>[fsr_members team="ehemalige"]</code><br><br>
    Tipp: Die Reihenfolge per Drag & Drop ändern. Änderungen werden automatisch gespeichert.
</div>

    <!-- Button & Status JETZT OBEN über den Mitgliedern -->
    <div class="fsr-admin-top-bar">
        <button type="button" class="button button-primary" id="add-member-btn">
            <span class="dashicons dashicons-plus" style="font-size:16px; vertical-align:middle; margin-top:-2px;"></span> Mitglied hinzufügen
        </button>
        <div id="fsr-save-indicator">✓ Änderungen gespeichert</div>
    </div>

    <!-- Filter & Steuerung -->
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

    <!-- Die sortier- und ausklappbaren Member-Kästen -->
    <div id="fsr-sortable-members">
        <?php foreach ($members as $index => $member) : 
            $team_class = 'fsr-team-' . ($member['team'] ?? 'gewaehlte');
            $full_display_name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        ?>
            <div class="fsr-member-row <?php echo $team_class; ?>">
                <div class="fsr-row-header">
                    <div class="fsr-toggle-trigger">
                        <span class="fsr-arrow">▶</span>
                        <span class="fsr-drag-handle">☰</span>
                        <span class="member-display-name"><?php echo esc_html($full_display_name ?: 'Unbenannt'); ?></span>
                        <span class="badge-team-name"><?php echo ucfirst($member['team'] ?? 'gewaehlte'); ?></span>
                    </div>
                </div>
                
                <div class="fsr-row-body" style="display:none;">
                    <div class="fsr-grid-inputs">
                        <label class="col-4">Vorname:<br><input type="text" class="fsr-input-firstname" name="fsr_members_settings[members][<?php echo $index; ?>][first_name]" value="<?php echo esc_attr($member['first_name'] ?? ''); ?>" required /></label>
                        <label class="col-4">Nachname:<br><input type="text" class="fsr-input-lastname" name="fsr_members_settings[members][<?php echo $index; ?>][last_name]" value="<?php echo esc_attr($member['last_name'] ?? ''); ?>" required /></label>
                        <label class="col-4">Bild-URL:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][image]" value="<?php echo esc_attr($member['image'] ?? ''); ?>" placeholder="https://..." /></label>
                        
                        <label class="col-4">Studiengang:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][studiengang]" value="<?php echo esc_attr($member['studiengang'] ?? ''); ?>" /></label>
                        <label class="col-2">Abschluss:<br>
                            <select name="fsr_members_settings[members][<?php echo $index; ?>][abschluss]">
                                <option value="" <?php selected($member['abschluss'] ?? '', ''); ?>>-</option>
                                <option value="B.Sc." <?php selected($member['abschluss'] ?? '', 'B.Sc.'); ?>>B.Sc.</option>
                                <option value="M.Sc." <?php selected($member['abschluss'] ?? '', 'M.Sc.'); ?>>M.Sc.</option>
                            </select>
                        </label>
                        <label class="col-2">Pronomen:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][pronomen]" value="<?php echo esc_attr($member['pronomen'] ?? ''); ?>" placeholder="er/ihm" /></label>
                        <label class="col-4">Mail-Präfix:<br><input type="text" class="fsr-input-email" name="fsr_members_settings[members][<?php echo $index; ?>][email_prefix]" value="<?php echo esc_attr($member['email_prefix'] ?? ''); ?>" /></label>
                        
                        <label class="col-4">Ämter (kommagetrennt):<br><input type="text" list="fsr-amter-list" name="fsr_members_settings[members][<?php echo $index; ?>][amt]" value="<?php echo esc_attr($member['amt'] ?? ''); ?>" /></label>
                        <label class="col-2">Erstes Jahr:<br><input type="text" name="fsr_members_settings[members][<?php echo $index; ?>][erstes_jahr]" value="<?php echo esc_attr($member['erstes_jahr'] ?? ''); ?>" /></label>
                        <label class="col-2">Semester:<br><input type="number" name="fsr_members_settings[members][<?php echo $index; ?>][semester_anzahl]" value="<?php echo esc_attr($member['semester_anzahl'] ?? ''); ?>" /></label>
                        <label class="col-4">Team:<br>
                            <select class="fsr-team-selector" name="fsr_members_settings[members][<?php echo $index; ?>][team]">
                                <option value="gewaehlte" <?php selected($member['team'] ?? '', 'gewaehlte'); ?>>Gewählte</option>
                                <option value="helfer" <?php selected($member['team'] ?? '', 'helfer'); ?>>Helfer</option>
                                <option value="ehemalige" <?php selected($member['team'] ?? '', 'ehemalige'); ?>>Ehemalige</option>
                            </select>
                        </label>
                    </div>
                    
                    <div class="fsr-row-footer-actions">
                        <button type="button" class="button button-link-delete remove-member">Dauerhaft löschen</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<input type="hidden" id="fsr_sort_nonce" value="<?php echo wp_create_nonce('fsr-member-sort-nonce'); ?>">

<script>
jQuery(document).ready(function($) {
    const nonce = $('#fsr_sort_nonce').val();

    function slugify(text) {
        return text.toString().toLowerCase()
            .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
            .replace(/[^a-z0-9_.-]/g, '');
    }

    function triggerAutoSave() {
        reindexRows();
        $('#fsr-save-indicator').text('Speichert...').css({'color':'#d97706', 'background':'#fffbeb', 'border-color':'#fde68a'}).fadeIn();
        const formData = $('#fsr-sortable-members :input').serialize();
        $.ajax({
            url: ajaxurl, type: 'POST', data: { action: 'fsr_save_member_order', order: formData, nonce: nonce },
            success: function(response) {
                if(response.success) {
                    $('#fsr-save-indicator').text('✓ Gespeichert').css({'color':'#16a34a', 'background':'#f0fdf4', 'border-color':'#bbf7d0'}).delay(1500).fadeOut();
                } else {
                    $('#fsr-save-indicator').text('❌ Fehler').css({'color':'#dc2626', 'background':'#fef2f2', 'border-color':'#fecaca'});
                }
            }
        });
    }

    $("#fsr-sortable-members").sortable({
        handle: '.fsr-row-header', placeholder: "ui-state-highlight", forcePlaceholderSize: true,
        start: function(e, ui){ ui.placeholder.css({'height': ui.item.find('.fsr-row-header').outerHeight()}); },
        update: function() { triggerAutoSave(); }
    });

    // Geflickter Akkordeon-Trigger: Setzt jetzt zuverlässig die CSS-Klasse am Hauptkasten an
    $(document).on('click', '.fsr-toggle-trigger', function() {
        const row = $(this).closest('.fsr-member-row');
        const body = row.find('.fsr-row-body');
        
        body.slideToggle(200, function() {
            if(body.is(':visible')) {
                row.addClass('is-expanded');
            } else {
                row.removeClass('is-expanded');
            }
        });
    });

    $('#fsr-expand-all').on('click', function() { $('.fsr-row-body').slideDown(200); $('.fsr-member-row').addClass('is-expanded'); });
    $('#fsr-collapse-all').on('click', function() { $('.fsr-row-body').slideUp(200); $('.fsr-member-row').removeClass('is-expanded'); });

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

    $(document).on('change', '.fsr-team-selector', function() {
        const row = $(this).closest('.fsr-member-row'); const val = $(this).val();
        row.removeClass('fsr-team-gewaehlte fsr-team-helfer fsr-team-ehemalige').addClass('fsr-team-' + val);
        row.find('.badge-team-name').text(val.charAt(0).toUpperCase() + val.slice(1));
    });

    $('.fsr-filter-btn').on('click', function() {
        $('.fsr-filter-btn').removeClass('active'); $(this).addClass('active');
        const filter = $(this).data('filter');
        if(filter === 'all') { $('.fsr-member-row').show(); } else { $('.fsr-member-row').hide(); $('.fsr-team-' + filter).show(); }
    });

    $(document).on('click', '.remove-member', function() { if(confirm('Mitglied wirklich löschen?')) { $(this).closest('.fsr-member-row').remove(); triggerAutoSave(); } });

    $('#add-member-btn').on('click', function() {
        const index = $('#fsr-sortable-members').children().length;
        const html = `
        <div class="fsr-member-row fsr-team-gewaehlte is-expanded">
            <div class="fsr-row-header">
                <div class="fsr-toggle-trigger">
                    <span class="fsr-arrow">▶</span>
                    <span class="fsr-drag-handle">☰</span>
                    <span class="member-display-name">Neues Mitglied</span>
                    <span class="badge-team-name">Gewaehlte</span>
                </div>
            </div>
            <div class="fsr-row-body" style="display:block;">
                <div class="fsr-grid-inputs">
                    <label class="col-4">Vorname:<br><input type="text" class="fsr-input-firstname" name="fsr_members_settings[members][${index}][first_name]" required /></label>
                    <label class="col-4">Nachname:<br><input type="text" class="fsr-input-lastname" name="fsr_members_settings[members][${index}][last_name]" required /></label>
                    <label class="col-4">Bild-URL:<br><input type="text" name="fsr_members_settings[members][${index}][image]" placeholder="https://..." /></label>
                    <label class="col-4">Studiengang:<br><input type="text" name="fsr_members_settings[members][${index}][studiengang]" /></label>
                    <label class="col-2">Abschluss:<br><select name="fsr_members_settings[members][${index}][abschluss]"><option value="">-</option><option value="B.Sc.">B.Sc.</option><option value="M.Sc.">M.Sc.</option></select></label>
                    <label class="col-2">Pronomen:<br><input type="text" name="fsr_members_settings[members][${index}][pronomen]" placeholder="er/ihm" /></label>
                    <label class="col-4">Mail-Präfix:<br><input type="text" class="fsr-input-email" name="fsr_members_settings[members][${index}][email_prefix]" /></label>
                    <label class="col-4">Ämter:<br><input type="text" list="fsr-amter-list" name="fsr_members_settings[members][${index}][amt]" /></label>
                    <label class="col-2">Erstes Jahr:<br><input type="text" name="fsr_members_settings[members][${index}][erstes_jahr]" /></label>
                    <label class="col-2">Semester:<br><input type="number" name="fsr_members_settings[members][${index}][semester_anzahl]" /></label>
                    <label class="col-4">Team:<br><select class="fsr-team-selector" name="fsr_members_settings[members][${index}][team]"><option value="gewaehlte">Gewählte</option><option value="helfer">Helfer</option><option value="ehemalige">Ehemalige</option></select></label>
                </div>
                <div class="fsr-row-footer-actions">
                    <button type="button" class="button button-link-delete remove-member">Dauerhaft löschen</button>
                </div>
            </div>
        </div>`;
        $('#fsr-sortable-members').prepend(html); 
        triggerAutoSave();
    });

    function reindexRows() {
        $('#fsr-sortable-members .fsr-member-row').each(function(index, row) {
            $(row).find('input, select').each(function() {
                const nameAttr = $(this).attr('name');
                if(nameAttr) { $(this).attr('name', nameAttr.replace(/\[members\]\[\d+\]/, '[members][' + index + ']')); }
            });
        });
    }
});
</script>