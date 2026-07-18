<?php
function fsr_updates_render_admin_interface() {
    $settings = wp_parse_args(
        get_option('fsr_update_settings', []),
        [
            'github_repo' => '',
            'branch' => 'main',
            'mode' => 'release',
            'fast_update' => false,
            'check_admin' => true,
        ]
    );
    $remote_version = get_option(
        'fsr_remote_version',
        'Nicht geprüft'
    );
    $remote_commit_message = get_option(
        'fsr_remote_commit_message',
        'Nicht geprüft'
    );
    $remote_checked = get_option(
        'fsr_remote_checked',
        'Nicht geprüft'
    );
    $local_version = defined('FSR_PLUGIN_VERSION')
        ? FSR_PLUGIN_VERSION
        : 'Unbekannt';
    ?>
    <div class="fsr-update-settings">
        <h2>GitHub Updates</h2>
        <p>
            Hier kannst du steuern, wie das Plugin Updates von GitHub beziehen soll.
        </p>
        <table class="form-table">
        </table>
        <form method="post" action="options.php">
            <?php
            settings_fields(
                'fsr_update_settings'
            );
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        GitHub Repository
                    </th>
                    <td>
                        <input
                            type="text"
                            class="regular-text"
                            name="fsr_update_settings[github_repo]"
                            value="<?php echo esc_attr(
                                $settings['github_repo']
                            ); ?>"
                        >
                        <p class="description">
                            Format: Benutzer/Repository (z.B. Citro111/FSR-Plugin)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        Entwicklungs-Branch
                    </th>
                    <td>
                        <input
                            type="text"
                            class="regular-text"
                            name="fsr_update_settings[branch]"
                            value="<?php echo esc_attr(
                                $settings['branch']
                            ); ?>"
                        >
                        <p class="description">
                            Wird verwendet, wenn "Entwicklungs-Branch" aktiviert ist.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        Update Quelle
                    </th>
                    <td>
                        <label>
                            <input
                                type="radio"
                                name="fsr_update_settings[mode]"
                                value="release"
                                <?php checked(
                                    $settings['mode'],
                                    'release'
                                ); ?>
                            >
                            GitHub Releases
                        </label>
                        <br>
                        <label>
                            <input
                                type="radio"
                                name="fsr_update_settings[mode]"
                                value="branch"
                                <?php checked(
                                    $settings['mode'],
                                    'branch'
                                ); ?>
                            >
                            Entwicklungs-Branch
                            <p value="<?php echo esc_attr($settings['branch']); ?>">
                                <small>Derzeitiger Branch: <?php echo esc_html($settings['branch']); ?></small>
                            </p>
                        </label>
                        <p class="description">
                            Releases sind für produktive Installationen gedacht.
                            Der Branch-Modus zieht immer den aktuellsten Entwicklungsstand.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        Automatisierung
                    </th>
                    <td>
                        <label>
                            <input
                                type="hidden"
                                name="fsr_update_settings[check_update_admin]"
                                value="0"
                            >
                            <input
                                type="checkbox"
                                name="fsr_update_settings[check_update_admin]"
                                value="1"
                                <?php checked(
                                    $settings['check_update_admin'],
                                    true
                                ); ?>
                            >
                            Bei Admin-Aufrufen automatisch aktuallisieren
                        </label>
                        <br>
                        <label>
                            <input
                                type="hidden"
                                name="fsr_update_settings[fast_update]"
                                value="0"
                            >
                            <input
                                type="checkbox"
                                name="fsr_update_settings[fast_update]"
                                value="1"
                                <?php checked(
                                    $settings['fast_update'],
                                    true
                                ); ?>
                            >
                            Neusten Updates schnell installieren
                        </label>
                    </td>
                </tr>
            </table>
            <?php
            submit_button(
                'Update Einstellungen speichern'
            );
            ?>
        </form>
        <hr>
        <h2>Status</h2>
        <table class="widefat striped">
            <tbody>
            <tr>
                <td>
                    Installierte Version
                </td>
                <td>
                    <?php echo esc_html($local_version); ?>
                </td>
            </tr>
            <tr>
                <td>
                    GitHub Version
                </td>
                <td>
                    <?php echo esc_html($remote_version); ?>
                </td>
            </tr>
            <tr>
                <td>
                    GitHub Commit Message
                </td>
                <td>
                    <?php echo esc_html($remote_commit_message); ?>
                </td>
            </tr>
            <tr>
                <td>
                    Zu letzt gecheckt
                </td>
                <td>
                    <?php echo esc_html($remote_checked); ?>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <form method="post"
              action="<?php echo esc_url(
                  admin_url('admin-post.php')
              ); ?>">
            <input type="hidden"
                   name="action"
                   value="fsr_manual_install">
                <?php
                wp_nonce_field(
                    'fsr_manual_install'
                );
                ?>
                <?php
                submit_button(
                    'Nach Updates suchen & installieren',
                    'primary',
                    'submit',
                    false
                );
                ?>
        </form>
        <form method="post"
              action="<?php echo esc_url(
                  admin_url('admin-post.php')
              ); ?>">
            <input type="hidden"
                   name="action"
                   value="fsr_clear_update_cache">
                <?php
                wp_nonce_field(
                    'fsr_clear_update_cache'
                );
                ?>
                <?php
                submit_button(
                    'Update Cache löschen',
                    'secondary',
                    'submit',
                    false
                );
                ?>
        </form>
        <br>
            <h2>Update Log</h2>
            <div class="fsr-update-log">
                <?php
                $log = get_transient('fsr_updates_public_log');
                if ($log) {
                    echo '<pre>' . esc_html($log) . '</pre>';
                } else {
                    echo '';
                }
                delete_transient('fsr_updates_public_log');
                ?>
            </div>
        </br>
        <p>
            <strong>Hinweis:</strong> Die Update-Funktion ist experimentell.
        </p>
    </div>
    <?php
}