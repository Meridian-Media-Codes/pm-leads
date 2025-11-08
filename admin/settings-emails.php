<?php
if (!defined('ABSPATH')) exit;

/**
 * EMAIL SETTINGS — UI scaffold only
 * Appears under: Admin > PM Leads > Settings > Emails
 */

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
    echo '<p>Configure email templates sent to customers and vendors after different actions.</p>';

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

    switch ($sub) {

        case 'new_lead_customer':
            pm_leads_template_placeholder('New Lead → Customer');
            break;

        case 'new_lead_vendors':
            pm_leads_template_placeholder('New Lead → Vendors');
            break;

        case 'lead_purchased_vendor':
            pm_leads_template_placeholder('Lead Purchased → Vendor');
            break;

        case 'lead_purchased_customer':
            pm_leads_template_placeholder('Lead Purchased → Customer');
            break;

        case 'credits_purchased_vendor':
            pm_leads_template_placeholder('Credits Purchased → Vendor');
            break;

        case 'low_credits_vendor':
            pm_leads_template_placeholder('Low Credits → Vendor');
            break;

        case 'vendor_approved_vendor':
            pm_leads_template_placeholder('Vendor Approved → Vendor');
            break;
    }

    echo '</div>'; // content box
    echo '</div>'; // wrap
}


/**
 * Draw sub-tabs
 */
function pm_leads_email_subtab_link($id, $label, $current) {

    $url = add_query_arg([
        'page'      => 'pm-leads-settings',
        'tab'       => 'emails',
        'email_tab' => $id
    ], admin_url('admin.php'));

    $class = 'nav-tab';
    if ($current === $id) $class .= ' nav-tab-active';

    echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}


/**
 * Placeholder — will be replaced with editable fields later
 */
function pm_leads_template_placeholder($title) {

    echo '<h3>' . esc_html($title) . '</h3>';
    echo '<p>Template editor coming next...</p>';

    echo '<table class="form-table" style="max-width:800px;">';
    echo '<tr>';
    echo '<th><label>Subject</label></th>';
    echo '<td><input type="text" class="regular-text" placeholder="Email subject…" disabled></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th><label>Body</label></th>';
    echo '<td><textarea rows="10" style="width:100%;" placeholder="Email body…" disabled></textarea></td>';
    echo '</tr>';

    echo '</table>';
}
