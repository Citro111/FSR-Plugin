<?php

function fsr_updates_register_settings() {

    register_setting(
        'fsr_updates_settings',
        'fsr_updates_settings'
    );

}

add_action(
    'admin_init',
    'fsr_updates_register_settings'
);

function fsr_updates_render_admin_interface() {
    $settings = wp_parse_args(
        get_option('fsr_updates_settings', []),
        [
            'mode' => 'release',
            'auto_update' => false,
            'check_admin' => true,
        ]
    );
    $remote_version = get_option(
        'fsr_remote_version',
        'Noch nicht geprüft'
    );
    $remote_commit = get_option(
        'fsr_remote_commit',
        'Noch nicht geprüft'
    );
    $local_version = defined('FSR_ETIT_VERSION')
        ? FSR_ETIT_VERSION
        : 'Unbekannt';
    ?>
    <div class="fsr-update-settings">
        <h2>GitHub Updates</h2>
        <p>
            Hier kannst du steuern, wie das Plugin Updates von GitHub beziehen soll.
        </p>
        <form method="post" action="options.php">
            <?php
            settings_fields(
                'fsr_updates_settings'
            );
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        Update Quelle
                    </th>
                    <td>
                        <label>
                            <input
                                type="radio"
                                name="fsr_updates_settings[mode]"
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
                                name="fsr_updates_settings[mode]"
                                value="branch"
                                <?php checked(
                                    $settings['mode'],
                                    'branch'
                                ); ?>
                            >
                            Entwicklungs-Branch (main)
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
                                type="checkbox"
                                name="fsr_updates_settings[check_admin]"
                                value="1"
                                <?php checked(
                                    $settings['check_admin'],
                                    true
                                ); ?>
                            >
                            Bei Admin-Aufrufen automatisch prüfen
                        </label>
                        <br>
                        <label>
                            <input
                                type="checkbox"
                                name="fsr_updates_settings[auto_update]"
                                value="1"
                                <?php checked(
                                    $settings['auto_update'],
                                    true
                                ); ?>
                            >
                            Updates automatisch installieren
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
                    GitHub Commit
                </td>
                <td>
                    <?php echo esc_html($remote_commit); ?>
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
                   value="fsr_check_update">
            <?php
            wp_nonce_field(
                'fsr_check_update'
            );
            ?>
            <?php
            submit_button(
                'Jetzt nach Updates suchen',
                'primary',
                'submit',
                false
            );
            ?>
        </form>
        <form method="post"
              action="<?php echo esc_url(
                  admin_url('admin-post.php')
              ); ?>"
              style="margin-top:10px;">
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
    </div>
    <?php
}