<?php
if(!defined('ABSPATH')) exit;
function fsr_dw_render_admin_fields() {

    $s = fsr_dw_get_settings();

    echo '<h3>DokuWiki Einstellungen</h3>';

    echo '<table class="form-table">';

    echo "<tr>
        <th>DokuWiki URL</th>
        <td>
            <input style='width:400px' 
            name='dw_bridge_settings[base_url]' 
            value='".esc_attr($s['base_url'])."'>
        </td>
    </tr>";

    echo "<tr>
        <th>Startseite</th>
        <td>
            <input name='dw_bridge_settings[start_page]' 
            value='".esc_attr($s['start_page'])."'>
        </td>
    </tr>";

    echo "<tr>
        <th>Cache (Sekunden)</th>
        <td>
            <input type='number' 
            name='dw_bridge_settings[cache_time]' 
            value='".esc_attr($s['cache_time'])."'>
        </td>
    </tr>";

    echo '</table>';

    global $wpdb;

    echo '<h3>DokuWiki Cache</h3>';

    $transients = $wpdb->get_results(
        "
        SELECT option_name, option_value
        FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_timeout_dw_%'
        "
    );

    if ($transients) {
        foreach ($transients as $transient) {
            $name = str_replace('_transient_timeout_', '', $transient->option_name);
            $time = intval($transient->option_value);
            echo '<p>';
            echo esc_html($name) . ': ';
            echo $time . ' (' . date('Y-m-d H:i:s', $time) . ')';
            if ($time < time()) {
                echo ' <strong>(abgelaufen)</strong>';
            }
            echo '</p>';
        }
    } else {
        echo '<p>Keine Cache-Einträge gefunden.</p>';
    }
    echo '<p>';
    echo '<button type="submit" name="dw_clear_cache" value="1" class="button">';
    echo 'DokuWiki Cache löschen';
    echo '</button>';
    echo '</p>';
}   
