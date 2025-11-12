<?php
if ( ! defined('ABSPATH') ) exit;

// Default options
function pm_leads_default_options() {
    return [
        'price_per_lead'   => '10.00',
        'purchase_limit'   => 5,
        'default_radius'   => 50,
        'google_api_key'   => '',
        'emails_enabled'   => 1,
        'page_vendor_dash' => 0,
        'page_lead_form'   => 0,
    ];
}

function pm_leads_get_options() {
    $defaults = pm_leads_default_options();
    $opts = get_option('pm_leads_options', []);
    if (! is_array($opts)) $opts = [];
    return wp_parse_args($opts, $defaults);
}

function pm_leads_register_settings() {
    register_setting('pm_leads_options_group', 'pm_leads_options', [
        'type' => 'array',
        'sanitize_callback' => 'pm_leads_sanitize_options',
        'default' => pm_leads_default_options(),
    ]);

    add_settings_section('pm_leads_general', __('General', 'pm-leads'), '__return_false', 'pm_leads_settings');
    add_settings_field('price_per_lead', __('Price per lead', 'pm-leads'), 'pm_leads_field_price', 'pm_leads_settings', 'pm_leads_general');
    add_settings_field('purchase_limit', __('Default lead purchase limit', 'pm-leads'), 'pm_leads_field_limit', 'pm_leads_settings', 'pm_leads_general');
    add_settings_field('default_radius', __('Default search radius (miles)', 'pm-leads'), 'pm_leads_field_radius', 'pm_leads_settings', 'pm_leads_general');
    add_settings_field('google_api_key', __('Google Maps API key', 'pm-leads'), 'pm_leads_field_api', 'pm_leads_settings', 'pm_leads_general');
}
add_action('admin_init', 'pm_leads_register_settings');

function pm_leads_sanitize_options($input) {
    $out = pm_leads_get_options();
    $out['price_per_lead'] = isset($input['price_per_lead']) ? sanitize_text_field($input['price_per_lead']) : $out['price_per_lead'];
    $out['purchase_limit'] = isset($input['purchase_limit']) ? absint($input['purchase_limit']) : $out['purchase_limit'];
    $out['default_radius'] = isset($input['default_radius']) ? absint($input['default_radius']) : $out['default_radius'];
    $out['google_api_key'] = isset($input['google_api_key']) ? sanitize_text_field($input['google_api_key']) : $out['google_api_key'];
    return $out;
}

// Field renderers
function pm_leads_field_price() {
    $o = pm_leads_get_options();
    echo '<input type="text" name="pm_leads_options[price_per_lead]" value="' . esc_attr($o['price_per_lead']) . '" class="regular-text" />';
}
function pm_leads_field_limit() {
    $o = pm_leads_get_options();
    echo '<input type="number" min="1" name="pm_leads_options[purchase_limit]" value="' . esc_attr($o['purchase_limit']) . '" class="small-text" />';
}
function pm_leads_field_radius() {
    $o = pm_leads_get_options();
    echo '<input type="number" min="1" name="pm_leads_options[default_radius]" value="' . esc_attr($o['default_radius']) . '" class="small-text" />';
}
function pm_leads_field_api() {
    $o = pm_leads_get_options();
    echo '<input type="password" name="pm_leads_options[google_api_key]" value="' . esc_attr($o['google_api_key']) . '" class="regular-text" autocomplete="off" />';
    echo '<p class="description">Used for geocoding and distance calculations. The key is hidden for security.</p>';
}

