<?php
if (!defined('ABSPATH')) exit;

/**
 * EMAIL SETTINGS
 * Admin → PM Leads → Settings → Emails
 * One tab per template, values saved in pm_leads_options
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
   Merge tag cheat-sheet
--------------------------------------------- */

/**
 * Return allowed merge tags for a given template key.
 * Tags are simple {tag_name} placeholders you’ll replace when sending the email.
 */
function pm_leads_allowed_merge_tags($key) {
    // Common buckets
    $customer = [
        '{customer_name}', '{customer_email}', '{customer_phone}',
        '{current_postcode}', '{current_address}', '{bedrooms_current}',
        '{new_postcode}', '{new_address}', '{bedrooms_new}',
        '{customer_message}',
    ];

    $vendor = [
        '{vendor_name}', '{vendor_email}', '{vendor_company}', '{vendor_phone}',
        '{vendor_service_radius}', '{vendor_credits}',
    ];

    $job_misc = [
        '{job_id}', '{purchases}', '{purchases_left}', '{purchase_limit}',
        '{job_from_lat}', '{job_from_lng}', '{job_to_lat}', '{job_to_lng}',
        '{job_distance_miles}', '{distance_vendor_to_origin_miles}',
    ];

    $site = [
        '{site_name}', '{site_url}', '{dashboard_url}',
    ];

    $credit = [
        '{credits_purchased}', '{credit_unit_price}', '{new_credit_balance}',
    ];

    // Per-template selections
    switch ($key) {
        case 'new_lead_customer':
            return array_merge($customer, $site);

        case 'new_lead_vendors':
            return array_merge($vendor, $customer, $job_misc, $site);

        case 'lead_purchased_vendor':
            return array_merge($vendor, $customer, $job_misc, $site);

        case 'lead_purchased_customer':
            return array_merge($customer, [
                '{vendor_name}', '{vendor_email}', '{vendor_company}', '{vendor_phone}',
            ], $job_misc, $site);

        case 'credits_purchased_vendor':
            return array_merge($vendor, $credit, $site);

        case 'low_credits_vendor':
            return array_merge($vendor, ['{low_credit_threshold}'], $site);

        case 'vendor_approved_vendor':
            return array_merge($vendor, $site);

        default:
            return $site;
    }
}

/** Render the cheat-sheet under the editor */
function pm_leads_render_merge_tag_help($key) {
    $tags = pm_leads_allowed_merge_tags($key);
    if (empty($tags)) return;

    echo '<hr style="margin:20px 0;">';
    echo '<h4 style="margin:0 0 8px;">Available merge tags</h4>';
    echo '<p style="margin:0 0 8px;">Copy and paste these placeholders into the subject or body. They will be replaced when the email is sent.</p>';

    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
    foreach ($tags as $t) {
        echo '<code style="padding:4px 6px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;display:inline-block;">' . esc_html($t) . '</code>';
    }
    echo '</div>';

    echo '<p style="margin-top:12px;"><em>Tip: keep subjects short and avoid HTML in the subject line.</em></p>';
}

/* ---------------------------------------------
   Save Handler (one template at a time)
--------------------------------------------- */
add_action('admin_init', function () {

    if (empty($_POST['pm_email_key'])) {
        return;
    }

    $key = sanitize_key($_POST['pm_email_key']);

    if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], "pm_save_template_{$key}")) {
        return;
    }

    pm_leads_save_email_template($key, [
        'enabled' => isset($_POST['enabled']) ? 1 : 0,
        'subject' => $_POST['subject'] ?? '',
        'body'    => $_POST['body'] ?? '',
    ]);

    // redirect back to same tab
    $target = add_query_arg([
        'page'      => 'pm-leads-settings',
        'tab'       => 'emails',
        'email_tab' => $key,
        'saved'     => '1',
    ], admin_url('admin.php'));

    wp_safe_redirect($target);
    exit;
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

    $class = 'nav-tab' . ($current === $id ? ' nav-tab-active' : '');
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

    echo '<table class="form-table" style="max-width:880px;table-layout:fixed;">';

    // Enabled
    echo '<tr><th style="width:200px;"><label>Enable</label></th><td>';
    echo '<label><input type="checkbox" name="enabled" value="1" ' . checked($tpl['enabled'], 1, false) . '> Send this email</label>';
    echo '</td></tr>';

    // Subject
    echo '<tr><th><label>Subject</label></th><td>';
    echo '<input type="text" name="subject" value="' . esc_attr($tpl['subject']) . '" class="regular-text" style="width:100%"/>';
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
            'tinymce'       => [
                'menubar' => false,
                'toolbar' => 'bold italic bullist numlist link unlink undo redo',
            ],
            'quicktags'     => true,
        ]
    );
    echo '</td></tr>';

    echo '</table>';

    // Merge tag cheat-sheet
    pm_leads_render_merge_tag_help($key);

    echo '<p><button type="submit" class="button button-primary">Save template</button></p>';
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
    if (!in_array($sub, $allowed_tabs, true)) $sub = 'new_lead_customer';

    echo '<div class="wrap pm-email-settings">';
    echo '<h2>Email Templates</h2>';
    echo '<p>Configure the emails sent to vendors and customers.</p>';

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
