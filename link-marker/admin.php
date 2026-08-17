<?php
/**
 * FSR ET/IT Link Marker - Admin integration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the admin page. This is public on purpose so the existing plugin
 * admin code can call it directly if it has its own tab/router.
 */
function fsr_etit_link_marker_render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    if (isset($_POST['fsr_etit_link_marker_save'])) {
        check_admin_referer('fsr_etit_link_marker_settings');

        $allowed = ['all', 'admin', 'off'];

        $old_visibility = sanitize_key(wp_unslash($_POST['old_visibility'] ?? 'admin'));
        $missing_visibility = sanitize_key(wp_unslash($_POST['missing_visibility'] ?? 'all'));
        $empty_visibility = sanitize_key(wp_unslash($_POST['empty_visibility'] ?? 'all'));

        if (!in_array($old_visibility, $allowed, true)) {
            $old_visibility = 'admin';
        }
        if (!in_array($missing_visibility, $allowed, true)) {
            $missing_visibility = 'all';
        }
        if (!in_array($empty_visibility, $allowed, true)) {
            $empty_visibility = 'all';
        }

        update_option('fsr_etit_link_marker_settings', [
            'old_visibility' => $old_visibility,
            'missing_visibility' => $missing_visibility,
            'empty_visibility' => $empty_visibility,
        ], false);

        echo '<div class="notice notice-success is-dismissible"><p>Link-Marker-Einstellungen gespeichert.</p></div>';
    }

    $settings = fsr_etit_link_marker_get_settings();
    ?>
    <div class="wrap">
        <h1>FSR ET/IT Link-Marker</h1>
        <p>Steuert, welche problematischen Links im Frontend markiert werden.</p>

        <form method="post">
            <?php wp_nonce_field('fsr_etit_link_marker_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Alte Domain</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('old_visibility', $settings['old_visibility']); ?>
                        <p class="description">Links auf <code>fsr-etit.de</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">404 / fehlende Ziele</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('missing_visibility', $settings['missing_visibility']); ?>
                        <p class="description">Interne Links, deren Ziel als HTTP 404 erkannt wird.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Leere Seiten</th>
                    <td>
                        <?php fsr_etit_link_marker_visibility_select('empty_visibility', $settings['empty_visibility']); ?>
                        <p class="description">WordPress-Seiten, die praktisch nur aus Überschriften/Leerraum bestehen.</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="fsr_etit_link_marker_save" class="button button-primary">
                    Einstellungen speichern
                </button>
            </p>
        </form>
        <p>Die eigentliche Übersicht „Welche Seiten enthalten problematische Links?“ wird auf dieselben Statusfunktionen aufsetzen. Sie ist hier noch nicht automatisch integriert, damit dein bestehendes Admin-Layout nicht ungefragt ersetzt wird.</p>
    </div>
    <?php
}

function fsr_etit_link_marker_visibility_select(string $name, string $value): void {
    $options = [
        'all' => 'Alle Besucher',
        'admin' => 'Nur Administratoren',
        'off' => 'Deaktiviert',
    ];

    echo '<select name="' . esc_attr($name) . '">';
    foreach ($options as $option_value => $label) {
        printf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr($option_value),
            selected($value, $option_value, false),
            esc_html($label)
        );
    }
    echo '</select>';
}

function fsr_etit_link_marker_get_settings(): array {
    $defaults = [
        'old_visibility' => 'admin',
        'missing_visibility' => 'all',
        'empty_visibility' => 'all',
    ];

    $saved = get_option('fsr_etit_link_marker_settings', []);

    return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
}
