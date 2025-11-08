<?php
if (!defined('ABSPATH')) exit;

/**
 * Email Settings UI
 *
 * Appears as the "Emails" tab inside Settings.
 * All values stored under pm_leads_options.
 */

//
// 1. Register email settings
//
add_action('admin_init', function () {

    // Register our settings group
    register_setting('pm_leads_options_group', 'pm_leads_options');

    // Add section
    add_settings_section(
        'pm_leads_email_section',
        'Email Notifications',
        function () {
            echo '<p>Configure email templates sent for lead + vendor events.</p>';
        },
        'pm_leads_settings_emails'
    );

    // List of email templates
    $emails = pm_leads_email_templates_index();

    foreach ($emails as $key => $label) {

        add_settings_field(
            "pm_email_{$key}",
            esc_html($label),
            'pm_leads_render_email_template_field',
            'pm_leads_settings_emails',
            'pm_leads_email_section',
            [
                'key'   => $key,
                'label' => $label
            ]
        );
    }
});


//
// 2. Email template helper index
//
function pm_leads_email_templates_index() {
    return [
        'newlead_vendor'     => 'New Lead → Vendors',
        'newlead_customer'   => 'New Lead → Customer',
        'purchase_vendor'    => 'Lead Purchased → Vendor',
        'purchase_customer'  => 'Lead Purchased → Customer',
        'credits_vendor'     => 'Credits Purchased → Vendor',
        'low_credits_vendor' => 'Low Credits → Vendor',
        'vendor_approved'    => 'Vendor Approved → Vendor',
    ];
}

//
// 3. Field renderer
//
function pm_leads_render_email_template_field($args) {

    $opts = get_option('pm_leads_options', []);
    $key  = $args['key'];

    $enabled = isset($opts["{$key}_enabled"]) ? intval($opts["{$key}_enabled"]) : 0;
    $subject = isset($opts["{$key}_subject"]) ? $opts["{$key}_subject"] : '';
    $body    = isset($opts["{$key}_body"])    ? $opts["{$key}_body"]    : '';

    echo '<div style="padding:10px 0;">';

    // enabled
    echo '<p><label><input type="checkbox" name="pm_leads_options[' . esc_attr($key) . '_enabled]" value="1" ' . checked($enabled, 1, false) . '> Enable</label></p>';

    // subject
    echo '<p><input type="text" class="regular-text" name="pm_leads_options[' . esc_attr($key) . '_subject]" placeholder="Subject" value="' . esc_attr($subject) . '"></p>';

    // body
    echo '<p><textarea name="pm_leads_options[' . esc_attr($key) . '_body]" rows="5" class="large-text" placeholder="Email message body">' . esc_textarea($body) . '</textarea></p>';

    echo '<p><em>Available tags: {customer_name} {customer_email} {customer_phone} {current_postcode} {new_postcode} {purchase_count} {credits} etc</em></p>';

    echo '<hr></div>';
}


//
// 4. Add submenu tab
//
add_action('admin_menu', function () {

    add_submenu_page(
        'pm-leads',
        'Email Settings',
        'Email Settings',
        'manage_options',
        'pm-leads-settings-emails',
        'pm_leads_render_emails_settings'
    );
});


//
// 5. UI Page
//
function pm_leads_render_emails_settings() {

    ?>
    <div class="wrap">
        <h1>Email Settings</h1>

        <form method="post" action="options.php">
            <?php
            settings_fields('pm_leads_options_group');
            do_settings_sections('pm_leads_settings_emails');
            submit_button();
            ?>
        </form>
    </div>

    <?php
}
