<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'fsr_etit_admin_menu');
add_action('admin_init', 'fsr_etit_register_settings');

function fsr_etit_admin_menu(): void {
    add_menu_page(
        'FSR ET/IT Website Tools',
        'FSR ET/IT',
        'manage_options',
        'fsr-etit-settings',
        'fsr_etit_settings_page',
        'dashicons-admin-generic',
        65
    );

    $pages = [
        ['Updates', 'fsr-etit-settings', 'fsr_etit_settings_page'],
        ['DokuWiki', 'fsr-etit-settings-dokuwiki', 'fsr_etit_settings_page'],
        ['Mitgliedskarten', 'fsr-etit-settings-membercards', 'fsr_etit_settings_page'],
        ['Kalender', 'fsr-etit-settings-calendar', 'fsr_etit_settings_page'],
        ['Shortcodes', 'fsr-etit-settings-shortcodes', 'fsr_etit_settings_page'],
        ['Link Marker', 'fsr-etit-settings-links', 'fsr_etit_settings_page'],
    ];

    foreach ($pages as [$title, $slug, $callback]) {
        add_submenu_page(
            'fsr-etit-settings',
            $title . ' – FSR ET/IT',
            $title,
            'manage_options',
            $slug,
            $callback
        );
    }
}

function fsr_etit_register_settings(): void {
    register_setting(
        'fsr_etit_dokuwiki_settings',
        FSR_ETIT_OPTION_DOKUWIKI_SETTINGS,
        [
            'type'              => 'array',
            'sanitize_callback' => 'fsr_etit_dokuwiki_sanitize_settings',
            'default'           => [],
        ]
    );
}

function fsr_etit_settings_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Du hast keine Berechtigung, diese Seite aufzurufen.', 'fsr-etit-website-tools'));
    }

    $page_slug = isset($_GET['page'])
        ? sanitize_key(fsr_etit_scalar_string(wp_unslash($_GET['page'])))
        : 'fsr-etit-settings';

    $page_to_tab = [
        'fsr-etit-settings'             => 'updates',
        'fsr-etit-settings-dokuwiki'    => 'dokuwiki',
        'fsr-etit-settings-membercards' => 'membercards',
        'fsr-etit-settings-shortcodes'  => 'shortcodes',
        'fsr-etit-settings-calendar'    => 'calendar',
        'fsr-etit-settings-links'       => 'links'
    ];

    $active_tab = $page_to_tab[$page_slug] ?? 'updates';
    if (isset($_GET['tab'])) {
        $legacy_tab = sanitize_key(fsr_etit_scalar_string(wp_unslash($_GET['tab'])));
        if (in_array($legacy_tab, $page_to_tab, true)) {
            $active_tab = $legacy_tab;
        }
    }

    $tab_links = [
        'updates'     => admin_url('admin.php?page=fsr-etit-settings'),
        'dokuwiki'    => admin_url('admin.php?page=fsr-etit-settings-dokuwiki'),
        'membercards' => admin_url('admin.php?page=fsr-etit-settings-membercards'),
        'shortcodes'  => admin_url('admin.php?page=fsr-etit-settings-shortcodes'),
        'calendar'    => admin_url('admin.php?page=fsr-etit-settings-calendar'),
        'links'       => admin_url('admin.php?page=fsr-etit-settings-links')
    ];
    ?>
    <div class="wrap">
        <h1>FSR ET/IT Website Tools</h1>
        <nav class="nav-tab-wrapper" aria-label="Plugin-Bereiche">
            <?php
            $labels = [
                'updates'     => 'Updates',
                'dokuwiki'    => 'DokuWiki',
                'membercards' => 'Mitgliedskarten',
                'shortcodes'  => 'Shortcodes',
                'calendar'    => 'Kalender',
                'links'       => 'Links'
            ];
            foreach ($labels as $tab => $label) :
                $class = 'nav-tab' . ($active_tab === $tab ? ' nav-tab-active' : '');
                ?>
                <a href="<?php echo esc_url($tab_links[$tab]); ?>" class="<?php echo esc_attr($class); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($active_tab === 'dokuwiki') : ?>
            <form method="post" action="options.php" style="margin-top:20px;">
                <?php
                settings_fields('fsr_etit_dokuwiki_settings');
                fsr_etit_dokuwiki_render_admin_fields();
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab === 'membercards') : ?>
            <div style="margin-top:20px;">
                <?php fsr_etit_members_render_admin_interface(); ?>
            </div>
        <?php elseif ($active_tab === 'updates') : ?>
            <div style="margin-top:20px;">
                <?php fsr_etit_updates_render_admin_interface(); ?>
            </div>
        <?php elseif ($active_tab === 'calendar') : ?>
            <div style="margin-top:20px;">
                <?php fsr_etit_calendar_render_admin_interface(); ?>
            </div>
        <?php elseif ($active_tab === 'shortcodes') : ?>
            <div style="margin-top:20px;">
                <?php fsr_etit_render_shortcode_admin_page(); ?>
            </div>
        <?php elseif ($active_tab === 'links') : ?>
            <div style="margin-top:20px;">
                <?php fsr_etit_link_marker_render_admin_page(); ?>
            </div>
        <?php else : ?>
            <p>Dieser Bereich besitzt eine eigene Unterseite.</p>
        <?php endif; ?>
    </div>
    <?php
}

add_filter(
    'plugin_action_links_' . plugin_basename(FSR_ETIT_FILE),
    static function (array $links): array {
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=fsr-etit-settings')),
                esc_html__('Einstellungen', 'fsr-etit-website-tools')
            )
        );
        return $links;
    }
);
