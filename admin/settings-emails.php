<?php
if (!defined('ABSPATH')) exit;

/**
 * EMAIL SETTINGS
 * Appears under:
 * Admin → PM Leads → Settings → Emails
 *
 * One tab per template, stored under pm_leads_options
 */

/* ---------------------------------------------
   Helpers (get / save)
--------------------------------------------- */

/** Get one template */
function pm_leads_get_email_template($key) {

    $opts = get_option('pm_leads_options', []);

    return [
        'enabled' => !empty($opts["{$key}_enabled"]) ? 1 : 0,
        'subject' => $opts["{$key}_subject"] ?? '',
        'body'    => $opts["{$key}_body"]    ?? '',
    ];
}

/** Save one template */
function pm_leads_save_email_template($key, $data) {

    $opts = get_option('pm_leads_options', []);

    $opts["{$key}_enabled"] = !empty($data['enabled']) ? 1 : 0;
    $opts["{$key}_subject"] = sanitize_text_field($data['subject'] ?? '');
    $opts["{$key}_body"]    = wp_kses_post($data['body'] ?? '');

    update_option('pm_leads_options', $opts);
}


/* ---------------------------------------------
   Save Handler
--------------------------------------------- */
add_action('admin_init', function () {

    if (empty($_POST['pm_email_key']) || empty($_POST['_wpnonce'])) {
        return;
    }

    $key = sanitize_key($_POST['pm_email_key']);

    if (!wp_verify_nonce($_POST['_wpnonce'], "pm_save_template_{$key}")) {
        return;
    }

    pm_leads_save_email_template($key, [
        'enabled' => isset($_POST['enabled']) ? 1 : 0,
        'subject' => $_POST['subject'] ?? '',
        'body'    => $_POST['body'] ?? '',
    ]);

    add_action('admin_notices', function () {
        echo '<div class="notice notice-success"><p>Email template saved.</p></div>';
    });
});


/* ---------------------------------------------
   Sub-tabs helper
--------------------------------------------- */
function pm_leads_email_subtab_link($id, $label, $current) {

    $url = add_query_arg([
        'page'      => 'pm-leads-settings',
        'tab'       => 'emails',
        'email_tab' => $id
    ], admin_url('admin.php'));

    $class = 'nav-tab';
    if ($current === $id) {
        $class .= ' nav-tab-active';
    }

    echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}


/* ---------------------------------------------
   Template editor (UI)
--------------------------------------------- */
function pm_leads_template_editor($key, $title) {

    $tpl = pm_leads_get_email_template($key);

    echo '<h3>' . esc_html($title) . '</h3>';

    echo '<form method="post" style="margin-top:20px;">';
    wp_nonce_field("pm_save_template_{$key}");

    echo '<table class="form-table" style="max-width:800px;">';

    // Enabled
    echo '<tr><th><label>Enable</label></th><td>';
    echo '<input type="checkbox" name="enabled" value="1" ' . checked($tpl['enabled'], 1, false) . '>';
    echo '</td></tr>';

    // Subject
    echo '<tr><th><label>Subject</label></th><td>';
    echo '<input type="text" name="subject" value="' . esc_attr($tpl['subject']) . '" class="regular-text" />';
    echo '</td></tr>';

    // Body (rich text)
    echo '<tr><th><label>Body</label></th><td>';
    wp_editor(
        $tpl['body'],
        "pm_email_body_{$key}",
        [
            'textarea_name' => 'body',
            'textarea_rows' => 12,
            'media_buttons' => false,
        ]
    );
    echo '</td></tr>';

    echo '</table>';

    echo '<p><button type="submit" class="button button-primary">Save Template</button></p>';
    echo '<input type="hidden" name="pm_email_key" value="' . esc_attr($key) . '">';
    echo '</form>';
}


/* ---------------------------------------------
   Main renderer
--------------------------------------------- */
function pm_leads_render_email_settings() {

    // Which inner tab?
    $allowed_tabs = [
        'new_lead_customer',
        'new_lead_vendors',
        'lead_purchased_vendor',
        'lead_purchased_customer',
        'credits_purchased_vendor',
        'low_credits_vendor',
        'vendor_approved_vendor'
    ];

    $sub = isset($_GET['email_tab']) ? sanitize_key($_GET['email_tab']) : 'new_lead_customer';
    if (!in_array($sub, $allowed_tabs, true)) {
        $sub = 'new_lead_customer';
    }

    echo '<div class="wrap pm-email-settings">';

    echo '<h2>Email Templates</h2>';
    echo '<p>Configure the emails sent to vendors + customers.</p>';

    /* Subtab navigation */
    echo '<h2 class="nav-tab-wrapper" style="margin-top:20px;">';

    pm_leads_email_subtab_link('new_lead_customer',       'New Lead → Customer',       $sub);
    pm_leads_email_subtab_link('new_lead_vendors',        'New Lead → Vendors',        $sub);
    pm_leads_email_subtab_link('lead_purchased_vendor',   'Lead Purchased → Vendor',   $sub);
    pm_leads_email_subtab_link('lead_purchased_customer', 'Lead Purchased → Customer', $sub);
    pm_leads_email_subtab_link('credits_purchased_vendor','Credits Purchased → Vendor',$sub);
    pm_leads_email_subtab_link('low_credits_vendor',      'Low Credits → Vendor',      $sub);
    pm_leads_email_subtab_link('vendor_approved_vendor',  'Vendor Approved → Vendor',  $sub);

    echo '</h2>';

    echo '<div style="padding:20px;background:#fff;border:1px solid #ddd;border-top:none;">';

    /* Load the active editor */
    switch ($sub) {

        case 'new_lead_customer':
            pm_leads_template_editor('new_lead_customer', 'New Lead → Customer');
            break;

        case 'new_lead_vendors':
            pm_leads_template_editor('new_lead_vendors', 'New Lead → Vendors');
            break;

        case 'lead_purchased_vendor':
            pm_leads_template_editor('lead_purchased_vendor', 'Lead Purchased → Vendor');
            break;

        case 'lead_purchased_customer':
            pm_leads_template_editor('lead_purchased_customer', 'Lead Purchased → Customer');
            break;

        case 'credits_purchased_vendor':
            pm_leads_template_editor('credits_purchased_vendor', 'Credits Purchased → Vendor');
            break;

        case 'low_credits_vendor':
            pm_leads_template_editor('low_credits_vendor', 'Low Credits → Vendor');
            break;

        case 'vendor_approved_vendor':
            pm_leads_template_editor('vendor_approved_vendor', 'Vendor Approved → Vendor');
            break;
    }

    echo '</div></div>';
}
