<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>Shortcodes Übersicht</h1>
    <?php foreach ($shortcodes as $code => $data): ?>
    <div class="card">
        <h2>
            <?php echo esc_html($data['title']); ?>
        </h2>
        <p>
            <strong>[<?php echo esc_html($code); ?>]</strong>
        </p>
        <p>
            <?php echo esc_html($data['description']); ?>
        </p>
        <h3>Beispiel</h3>
        <code>
            <?php echo esc_html($data['example']); ?>
        </code>
        <?php if (!empty($data['attributes'])): ?>
        <h3>Attribute</h3>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Beschreibung</th>
                    <th>Mögliche Werte</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['attributes'] as $name=>$attr): ?>
                <tr>
                    <td>
                        <?php echo esc_html($name); ?>
                    </td>
                    <td>
                        <?php echo esc_html($attr['description']); ?>
                    </td>
                    <td>
                        <?php echo esc_html($attr['values']); ?>
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
                (<?php echo esc_html($place['type']); ?>)
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p>
            Noch nicht verwendet.
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>