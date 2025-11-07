<?php
if (!defined('ABSPATH')) exit;

/**
 * PM Leads Admin Menus
 */
add_action('admin_menu', function () {

    add_menu_page(
        __('PM Leads', 'pm-leads'),
        __('PM Leads', 'pm-leads'),
        'manage_options',
        'pm-leads',
        'pm_leads_render_dashboard',
        'dashicons-admin-site-alt3',
        26
    );

    add_submenu_page(
        'pm-leads',
        __('Dashboard', 'pm-leads'),
        __('Dashboard', 'pm-leads'),
        'manage_options',
        'pm-leads',
        'pm_leads_render_dashboard'
    );

    add_submenu_page(
        'pm-leads',
        __('Vendors', 'pm-leads'),
        __('Vendors', 'pm-leads'),
        'manage_options',
        'pm-leads-vendors',
        'pm_leads_render_vendors'
    );

    add_submenu_page(
        'pm-leads',
        __('Jobs', 'pm-leads'),
        __('Jobs', 'pm-leads'),
        'manage_options',
        'pm-leads-jobs',
        'pm_leads_render_jobs'
    );

    add_submenu_page(
        'pm-leads',
        __('Settings', 'pm-leads'),
        __('Settings', 'pm-leads'),
        'manage_options',
        'pm-leads-settings',
        'pm_leads_render_settings'
    );
});

/**
 * Dashboard
 */
function pm_leads_render_dashboard() {
    echo '<div class="wrap"><h1>PM Leads</h1><p>Overview coming soon.</p></div>';
}


/**
 * Vendors (list + single profile inline)
 */
function pm_leads_render_vendors() {
    if (!current_user_can('manage_options')) return;

    /* SAVE HANDLER */
    if (!empty($_POST['pm_vendor_nonce']) && wp_verify_nonce($_POST['pm_vendor_nonce'], 'pm_vendor_save')) {

        $vid = absint($_POST['vendor_id']);

        if ($vid) {

            // Update WP user_email separately
            if (!empty($_POST['vendor_email'])) {
                wp_update_user([
                    'ID'         => $vid,
                    'user_email' => sanitize_email($_POST['vendor_email'])
                ]);
            }

            // Standard fields
            $fields = [
                'company_name',
                'first_name',
                'last_name',
                'contact_number',
                'business_postcode',
                'service_radius',
                'years_in_business'
            ];

            foreach ($fields as $k) {
                if (isset($_POST[$k])) {
                    update_user_meta($vid, $k, sanitize_text_field($_POST[$k]));
                }
            }

            // Credits
            if (isset($_POST['pm_credit_balance'])) {
                update_user_meta($vid, 'pm_credit_balance', intval($_POST['pm_credit_balance']));
            }

            // Re-geocode postcode
            if (!empty($_POST['business_postcode']) && function_exists('pm_leads_geocode')) {
                $coords = pm_leads_geocode(sanitize_text_field($_POST['business_postcode']));
                if (!empty($coords['lat']) && !empty($coords['lng'])) {
                    update_user_meta($vid, 'pm_vendor_lat', $coords['lat']);
                    update_user_meta($vid, 'pm_vendor_lng', $coords['lng']);
                }
            }

            echo '<div class="updated notice"><p>Vendor updated.</p></div>';
        }
    }


    /* SINGLE VENDOR VIEW */
    if (!empty($_GET['vendor_id'])) {

        $uid = absint($_GET['vendor_id']);
        $u = get_user_by('id', $uid);

        if (!$u) {
            echo '<div class="wrap"><h1>Vendor not found</h1></div>';
            return;
        }

        $fields = [
            'company_name'      => 'Company Name',
            'first_name'        => 'First Name',
            'last_name'         => 'Last Name',
            'vendor_email'      => 'Vendor Email',
            'contact_number'    => 'Contact Number',
            'business_postcode' => 'Business Postcode',
            'service_radius'    => 'Service Radius (miles)',
            'years_in_business' => 'Years In Business',
            'insurance_docs'    => 'Insurance Docs'
        ];

        $vals = [];
        foreach ($fields as $k => $label) {
            $vals[$k] = ($k === 'vendor_email')
                ? $u->user_email
                : get_user_meta($u->ID, $k, true);
        }

        $credits = intval(get_user_meta($u->ID, 'pm_credit_balance', true));

        echo '<div class="wrap"><h1>Vendor Profile</h1>';

        echo '<form method="post">';
        wp_nonce_field('pm_vendor_save','pm_vendor_nonce');
        echo '<input type="hidden" name="vendor_id" value="' . intval($u->ID) . '"/>';

        echo '<table class="form-table"><tbody>';

        foreach ($fields as $k => $label) {
            echo '<tr><th style="width:220px;"><label>' . esc_html($label) . '</label></th><td>';

            if ($k === 'insurance_docs' && !empty($vals[$k])) {
                $url = is_array($vals[$k]) ? reset($vals[$k]) : $vals[$k];
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View document</a>';
            } else {

                $type = ($k === 'service_radius' || $k === 'years_in_business')
                    ? 'number'
                    : 'text';

                echo '<input type="' . esc_attr($type) . '" 
                        name="' . esc_attr($k) . '" 
                        value="' . esc_attr($vals[$k]) . '" 
                        class="regular-text" />';
            }

            echo '</td></tr>';
        }

        // Credits editable
        echo '<tr><th><label>Credits</label></th><td>
                <input type="number" 
                       name="pm_credit_balance" 
                       value="' . esc_attr($credits) . '" 
                       class="small-text" />
              </td></tr>';

        echo '<tr><th>Registered</th><td>' . esc_html($u->user_registered) . '</td></tr>';

        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">Save</button>
                <a class="button" href="'.esc_url(admin_url('admin.php?page=pm-leads-vendors')).'">Back to Vendors</a>
              </p>';

        echo '</form></div>';
        return;
    }


    /* LIST VIEW */
    $vendors = get_users(['role'=>'pm_vendor','number'=>500]);

    echo '<div class="wrap"><h1>Vendors</h1>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Company/User</th><th>Email</th><th>Credits</th><th>Registered</th><th>Profile</th>';
    echo '</tr></thead><tbody>';

    if ($vendors) {
        foreach ($vendors as $v) {
            $credits = intval(get_user_meta($v->ID,'pm_credit_balance',true));
            $profile_url = esc_url(admin_url('admin.php?page=pm-leads-vendors&vendor_id='.intval($v->ID)));

            echo '<tr>';
            echo '<td>' . esc_html($v->display_name) . '</td>';
            echo '<td>' . esc_html($v->user_email) . '</td>';
            echo '<td>' . esc_html($credits) . '</td>';
            echo '<td>' . esc_html($v->user_registered) . '</td>';
            echo '<td><a class="button button-small" href="' . $profile_url . '">Open</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No vendors found.</td></tr>';
    }

    echo '</tbody></table></div>';
}


/* JOBS + SETTINGS unchanged from your version */

function pm_leads_render_jobs() { /* UNCHANGED */ }
function pm_leads_render_settings() { /* UNCHANGED */ }

