<?php
if (!defined('ABSPATH')) exit;

// Shortcode registrieren
add_shortcode('fsr_members', 'fsr_members_shortcode_renderer');

// AJAX Hooks für automatisches Speichern
add_action('wp_ajax_fsr_save_member_order', 'fsr_ajax_save_member_order_handler');

// Skripte und Styles im Backend laden
add_action('admin_enqueue_scripts', 'fsr_members_admin_assets');
function fsr_members_admin_assets($hook) {
    if (strpos($hook, 'fsr-etit-settings') !== false) {
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('fsr-members-admin-css', plugin_dir_url(__FILE__) . 'assets/admin-style.css', [], '1.0.0');
    }
}

// Daten-Helper
function fsr_get_members_data() {
    return get_option('fsr_members_settings', ['members' => []]);
}

function fsr_sanitize_members_data($input) {
    if (!isset($input['members']) || !is_array($input['members'])) {
        $input['members'] = [];
    }
    $input['members'] = array_values($input['members']);
    return $input;
}

// AJAX-Speicher-Handler
function fsr_ajax_save_member_order_handler() {
    check_ajax_referer('fsr-member-sort-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.');
    }
    parse_str($_POST['order'], $form_data);
    if (isset($form_data['fsr_members_settings'])) {
        $clean_data = fsr_sanitize_members_data($form_data['fsr_members_settings']);
        update_option('fsr_members_settings', $clean_data);
        wp_send_json_success('Reihenfolge gespeichert!');
    }
    wp_send_json_error('Fehler beim Verarbeiten.');
}

// Admin-Interface aufrufen
function fsr_members_render_admin_interface() {
    include plugin_dir_path(__FILE__) . 'templates/admin-interface.php';
}

// Frontend-Renderer aufrufen
function fsr_members_shortcode_renderer($atts) {
    $a = shortcode_atts(['team' => 'all'], $atts);
    $data = fsr_get_members_data();
    $members = $data['members'] ?? [];
    if (empty($members)) return '';

    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/frontend-grid.php';
    return ob_get_clean();
}