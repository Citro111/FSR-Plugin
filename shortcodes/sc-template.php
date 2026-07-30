<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Shortcodes Übersicht</h1>
    <?php foreach ($shortcodes as $code => $data): ?>
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
                            <?php echo esc_html($attr['description'] ?? ''); ?>
                        </td>
                        <td>
                            <?php echo esc_html($attr['values'] ?? ''); ?>
                        </td>
                        <td>
                            <?php echo esc_html($attr['default'] ?? ''); ?>
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
                        <a href="<?php echo esc_url($place['edit']); ?>">
                            <?php echo esc_html($place['title']); ?>
                        </a>
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
    <?php endforeach; ?>
</div>