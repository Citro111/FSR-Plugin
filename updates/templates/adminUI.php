<?php

if (!defined('ABSPATH')) {
    exit;
}

function fsr_etit_updates_render_admin_interface(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = fsr_etit_updates_settings();
    $notice = fsr_etit_updates_get_notice();
    if ($notice) {
        $notice_type = in_array($notice['type'] ?? '', ['error', 'warning', 'success', 'info'], true)
            ? $notice['type']
            : 'info';
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($notice_type),
            esc_html($notice['message'] ?? '')
        );
    }
    settings_errors(FSR_ETIT_OPTION_UPDATE_SETTINGS);

    $status = [
        'Installierte Version' => FSR_ETIT_VERSION,
        'GitHub-Version'       => fsr_etit_scalar_string(get_option('fsr_remote_version', 'Nicht geprüft')),
        'Letzte Prüfung'       => fsr_etit_scalar_string(get_option('fsr_remote_checked', 'Nicht geprüft')),
    ];
    ?>
    <div class="fsr-update-settings">
        <h2>GitHub-Updates</h2>
        <p>Produktiv empfiehlt sich der Release-Modus. Der Branch-Modus installiert ungeprüften Entwicklungsstand aus dem ausgewählten Branch.</p>

        <form method="post" action="options.php">
            <?php settings_fields('fsr_etit_update_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fsr-etit-github-repo">GitHub-Repository</label></th>
                    <td>
                        <input id="fsr-etit-github-repo" type="text" class="regular-text" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[github_repo]" value="<?php echo esc_attr($settings['github_repo']); ?>" required>
                        <p class="description">Format: Benutzer/Repository</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fsr-etit-github-branch">Entwicklungs-Branch</label></th>
                    <td><input id="fsr-etit-github-branch" type="text" class="regular-text" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[branch]" value="<?php echo esc_attr($settings['branch']); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row">Update-Quelle</th>
                    <td>
                        <label><input type="radio" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[mode]" value="release" <?php checked($settings['mode'], 'release'); ?>> GitHub-Releases</label><br>
                        <label><input type="radio" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[mode]" value="branch" <?php checked($settings['mode'], 'branch'); ?>> Entwicklungs-Branch</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Diagnose</th>
                    <td>
                        <input type="hidden" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[logging]" value="0">
                        <label><input type="checkbox" name="<?php echo esc_attr(FSR_ETIT_OPTION_UPDATE_SETTINGS); ?>[logging]" value="1" <?php checked(!empty($settings['logging'])); ?>> Debug-Logging aktivieren</label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Update-Einstellungen speichern'); ?>
        </form>

        <hr>
        <h2>Status</h2>
        <table class="widefat striped">
            <tbody>
                <?php foreach ($status as $label => $value) : ?>
                    <tr><td><?php echo esc_html($label); ?></td><td><?php echo esc_html($value); ?></td></tr>
                <?php endforeach; ?>
                <tr>
                    <td>GitHub-Änderungen</td>
                    <td><pre style="white-space:pre-wrap;margin:0;"><?php echo esc_html(fsr_etit_scalar_string(get_option('fsr_remote_commit_message', 'Nicht geprüft'))); ?></pre></td>
                </tr>
            </tbody>
        </table>

        <?php if (current_user_can('update_plugins')) : ?>
            <div style="margin-top:16px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="fsr_manual_install">
                    <?php wp_nonce_field('fsr_manual_install'); ?>
                    <?php submit_button('Nach Update suchen und installieren', 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="fsr_clear_update_cache">
                    <?php wp_nonce_field('fsr_clear_update_cache'); ?>
                    <?php submit_button('Update-Cache löschen', 'secondary', 'submit', false); ?>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
