<?php

if (!defined('ABSPATH')) {
    exit;
}

function fsr_etit_dokuwiki_render_admin_fields(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = fsr_etit_dokuwiki_get_settings();
    ?>
    <h2>DokuWiki-Einstellungen</h2>
    <?php settings_errors(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS); ?>
    <table class="form-table">
        <tr>
            <th scope="row"><label for="fsr-etit-dokuwiki-url">DokuWiki-URL</label></th>
            <td>
                <input
                    id="fsr-etit-dokuwiki-url"
                    class="regular-text"
                    type="url"
                    name="<?php echo esc_attr(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS); ?>[base_url]"
                    value="<?php echo esc_attr($settings['base_url']); ?>"
                    required
                >
                <p class="description">Aus Sicherheitsgründen ist ausschließlich eine öffentliche HTTPS-URL zulässig.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fsr-etit-dokuwiki-start">Startseite</label></th>
            <td>
                <input
                    id="fsr-etit-dokuwiki-start"
                    class="regular-text"
                    name="<?php echo esc_attr(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS); ?>[start_page]"
                    value="<?php echo esc_attr($settings['start_page']); ?>"
                    required
                >
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fsr-etit-dokuwiki-cache">Cache-Dauer</label></th>
            <td>
                <input
                    id="fsr-etit-dokuwiki-cache"
                    type="number"
                    min="0"
                    max="<?php echo esc_attr(DAY_IN_SECONDS); ?>"
                    name="<?php echo esc_attr(FSR_ETIT_OPTION_DOKUWIKI_SETTINGS); ?>[cache_time]"
                    value="<?php echo esc_attr($settings['cache_time']); ?>"
                > Sekunden
            </td>
        </tr>
    </table>

    <h2>DokuWiki-Cache</h2>
    <?php
    global $wpdb;
    $timeout_like = $wpdb->esc_like('_transient_timeout_fsr_etit_dokuwiki_') . '%';
    $transients = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 100",
            $timeout_like
        )
    );

    if ($transients) {
        foreach ($transients as $transient) {
            $name = str_replace('_transient_timeout_', '', $transient->option_name);
            $time = absint($transient->option_value);
            printf(
                '<p><code>%s</code>: %s%s</p>',
                esc_html($name),
                esc_html(wp_date('d.m.Y H:i:s', $time, wp_timezone())),
                $time < time() ? ' <strong>(abgelaufen)</strong>' : ''
            );
        }
    } else {
        echo '<p>Keine Cache-Einträge gefunden.</p>';
    }
    ?>
    <p>
        <button type="submit" name="dw_clear_cache" value="1" class="button">
            DokuWiki-Cache löschen
        </button>
    </p>
    <?php
}
