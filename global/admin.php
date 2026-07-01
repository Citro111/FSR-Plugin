<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'fsr_custom_admin_menu');
function fsr_custom_admin_menu() {
    // Eigenes Hauptmenü in der linken Sidebar erstellen
    // Falls du ein Hauptmenü registrierst, füge hinten das Dashicon an:
    add_menu_page(
        'FSR Mitglieder',            // Seitentitel
        'FSR Mitglieder',            // Menütitel in der Sidebar
        'manage_options',            // Berechtigung
        'fsr-etit-settings',         // Menu-Slug
        'fsr_custom_settings_page',  // Callback-Funktion
        'dashicons-admin-generic',       // Icon (Studentenhut)
        65                           // Position in der Sidebar
    );
}

add_action('admin_init', 'fsr_custom_register_global_settings');
function fsr_custom_register_global_settings() {
    register_setting('dw_bridge_settings', 'dw_bridge_settings');
}

function fsr_custom_settings_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dokuwiki';
    ?>
    <div class="wrap">
        <h1>FSR ET/IT Custom Plugin Konfiguration</h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=fsr-etit-settings&tab=dokuwiki" class="nav-tab <?php echo $active_tab == 'dokuwiki' ? 'nav-tab-active' : ''; ?>">DokuWiki Connector</a>
            <a href="?page=fsr-etit-settings&tab=membercards" class="nav-tab <?php echo $active_tab == 'membercards' ? 'nav-tab-active' : ''; ?>">Mitgliedskarten</a>
        </h2>

        <?php if ($active_tab == 'dokuwiki') : ?>
            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php
                settings_fields('dw_bridge_settings');
                fsr_dw_render_admin_fields();
                submit_button();
                ?>
            </form>
        <?php else : ?>
            <div style="margin-top: 20px;">
                <?php fsr_members_render_admin_interface(); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}