<?php
if (!defined('ABSPATH')) exit;

function fsr_etit_shortcode_admin_value($value) {
    if (is_array($value)) {
        $output = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $output[] = $key . ': ' . fsr_etit_shortcode_admin_value($item);
            } else {
                $output[] = (string) $item;
            }
        }
        return implode(', ', $output);
    }
    if (is_bool($value)) {
        return $value ? 'Ja' : 'Nein';
    }
    return (string) $value;
}
?>

<div class="wrap">
    <h1>Shortcode-Übersicht</h1>
    <?php foreach ($shortcodes as $code => $data): ?>
    <div style="margin-bottom:12px;">
        <details class="fsr-shortcode-accordion">
            <summary>
                <strong><?php echo esc_html($data['title']); ?></strong>
                <code>[<?php echo esc_html($code); ?>]</code>
                <?php if (!empty($data['description'])): ?>
                    <span>
                        - <?php echo esc_html($data['description']); ?>
                    </span>
                <?php endif; ?>
            </summary>
            <div class="fsr-shortcode-content">
                <h3>Shortcode</h3>
                <code>
                    <?php echo esc_html('[' . $code . ']'); ?>
                </code>
                <?php if (!empty($data['example'])): ?>
                    <h3>Beispiel</h3>
                    <code>
                        <?php echo esc_html($data['example']); ?>
                    </code>
                <?php endif; ?>
                <?php if (!empty($data['attributes'])): ?>
                <h3>Attribute</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Beschreibung</th>
                            <th>Mögliche Werte</th>
                            <th>Default</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data['attributes'] as $name => $attr): ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html($name); ?></code>
                            </td>
                            <td>
                                <?php echo esc_html(fsr_etit_shortcode_admin_value($attr['description'] ?? '')); ?>
                            </td>
                            <td>
                                <?php echo esc_html(fsr_etit_shortcode_admin_value($attr['values'] ?? '')); ?>
                            </td>
                            <td>
                                <?php echo esc_html(fsr_etit_shortcode_admin_value($attr['default'] ?? '')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <h3>Verwendet auf:</h3>
                <?php if (!empty($usage[$code])): ?>
                    <ul>
                    <?php foreach ($usage[$code] as $place): ?>
                        <li>
                            <?php if (!empty($place['edit'])) : ?>
                                <a href="<?php echo esc_url($place['edit']); ?>"><?php echo esc_html($place['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($place['title']); ?>
                            <?php endif; ?>
                            <small>
                                (<?php echo esc_html($place['type']); ?>,
                                <?php echo esc_html($place['status']); ?>)
                            </small>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>
                        Noch nicht verwendet.
                    </p>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <?php endforeach; ?>
</div>
