<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'fsr_custom_admin_menu');
function fsr_custom_admin_menu() {
    add_menu_page(
        'FSR ET/IT Einstellungen',
        'FSR ET/IT',
        'manage_options',
        'fsr-etit-settings',
        'fsr_custom_settings_page',
        'dashicons-admin-generic',
        65
    );

    add_submenu_page(
        'fsr-etit-settings',
        'DokuWiki Connector',
        'DokuWiki Connector',
        'manage_options',
        'fsr-etit-settings-dokuwiki',
        'fsr_custom_settings_page'
    );

    add_submenu_page(
        'fsr-etit-settings',
        'Mitgliedskarten',
        'Mitgliedskarten',
        'manage_options',
        'fsr-etit-settings-membercards',
        'fsr_custom_settings_page'
    );

    add_submenu_page(
        'fsr-etit-settings',
        'Office Hours',
        'Office Hours',
        'manage_options',
        'fsr-etit-settings-officehours',
        'fsr_custom_settings_page'
    );
}

add_action('admin_init', 'fsr_custom_register_global_settings');
function fsr_custom_register_global_settings() {
    register_setting('dw_bridge_settings', 'dw_bridge_settings');
}

function fsr_custom_settings_page() {
    $page_slug = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'fsr-etit-settings-dokuwiki';

    $page_to_tab = [
        'fsr-etit-settings' => 'dokuwiki',
        'fsr-etit-settings-dokuwiki' => 'dokuwiki',
        'fsr-etit-settings-membercards' => 'membercards',
        'fsr-etit-settings-officehours' => 'officehours',
    ];

    // Rueckwaertskompatibel: alte Links mit ?tab=... weiterhin unterstuetzen.
    if (isset($_GET['tab'])) {
        $active_tab = sanitize_text_field($_GET['tab']);
    } else {
        $active_tab = isset($page_to_tab[$page_slug]) ? $page_to_tab[$page_slug] : 'dokuwiki';
    }

    $tab_links = [
        'dokuwiki' => admin_url('admin.php?page=fsr-etit-settings-dokuwiki'),
        'membercards' => admin_url('admin.php?page=fsr-etit-settings-membercards'),
        'officehours' => admin_url('admin.php?page=fsr-etit-settings-officehours'),
    ];

    ?>
    <div class="wrap">
        <h1>FSR ET/IT Custom Plugin Konfiguration</h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url($tab_links['dokuwiki']); ?>" class="nav-tab <?php echo $active_tab == 'dokuwiki' ? 'nav-tab-active' : ''; ?>">DokuWiki Connector</a>
            <a href="<?php echo esc_url($tab_links['membercards']); ?>" class="nav-tab <?php echo $active_tab == 'membercards' ? 'nav-tab-active' : ''; ?>">Mitgliedskarten</a>
            <a href="<?php echo esc_url($tab_links['officehours']); ?>" class="nav-tab <?php echo $active_tab == 'officehours' ? 'nav-tab-active' : ''; ?>">Office Hours</a>
        </h2>

        <?php if ($active_tab == 'dokuwiki') : ?>
            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php
                settings_fields('dw_bridge_settings');
                fsr_dw_render_admin_fields();
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab == 'membercards') : ?>
            <div style="margin-top: 20px;">
                <?php fsr_members_render_admin_interface(); ?>
            </div>
        <?php else : ?>
            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php
                settings_fields('fsr_office_hours_settings');
                fsr_office_hours_render_admin_interface();
                submit_button('Office Hours speichern');
                ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}