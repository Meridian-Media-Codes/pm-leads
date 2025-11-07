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

function pm_leads_render_jobs() {
    if (!current_user_can('manage_options')) return;

    // Save handler for single job view
    if (isset($_POST['pm_job_nonce']) && wp_verify_nonce($_POST['pm_job_nonce'], 'pm_job_save')) {
        $job_id = absint($_POST['job_id'] ?? 0);
        if ($job_id) {
            $fields = [
                'customer_name','customer_email','customer_phone','customer_message',
                'current_postcode','current_address','bedrooms_current',
                'new_postcode','new_address','bedrooms_new','purchase_count'
            ];
            foreach ($fields as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($job_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
            echo '<div class="updated notice"><p>Job updated.</p></div>';
        }
    }

    // Single job view
    if (!empty($_GET['job_id'])) {
        $job_id = absint($_GET['job_id']);
        $job = get_post($job_id);
        if (!$job || $job->post_type !== 'pm_job') {
            echo '<div class="wrap"><h1>Job not found</h1></div>';
            return;
        }

        $fields = [
            'customer_name'     => 'Customer Name',
            'customer_email'    => 'Customer Email',
            'customer_phone'    => 'Customer Phone',
            'customer_message'  => 'Customer Message',
            'current_postcode'  => 'Current Postcode',
            'current_address'   => 'Current Address',
            'bedrooms_current'  => 'Current Bedrooms',
            'new_postcode'      => 'New Postcode',
            'new_address'       => 'New Address',
            'bedrooms_new'      => 'New Bedrooms',
            'purchase_count'    => 'Purchases'
        ];
        $vals = [];
        foreach ($fields as $k => $label) {
            $vals[$k] = get_post_meta($job_id, $k, true);
        }

        echo '<div class="wrap"><h1>Job #' . intval($job_id) . '</h1>';
        echo '<form method="post">';
        wp_nonce_field('pm_job_save', 'pm_job_nonce');
        echo '<input type="hidden" name="job_id" value="' . intval($job_id) . '"/>';

        echo '<table class="form-table"><tbody>';
        foreach ($fields as $k => $label) {
            $val = $vals[$k];
            echo '<tr><th scope="row"><label>' . esc_html($label) . '</label></th><td>';
            if ($k === 'customer_message' || $k === 'current_address' || $k === 'new_address') {
                echo '<textarea name="' . esc_attr($k) . '" rows="3" class="large-text">' . esc_textarea($val) . '</textarea>';
            } else {
                $type = ($k === 'bedrooms_current' || $k === 'bedrooms_new' || $k === 'purchase_count') ? 'number' : 'text';
                echo '<input type="' . $type . '" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '" class="regular-text" />';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=pm-leads-jobs')) . '">Back to Jobs</a></p>';
        echo '</form></div>';
        return;
    }

    // List view
    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => 100,
        'post_status'    => 'any',
    ]);

    echo '<div class="wrap"><h1>Jobs</h1>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>ID</th><th>From</th><th>To</th><th>Bedrooms</th><th>Status</th><th>Purchases</th>';
    echo '</tr></thead><tbody>';

    if ($jobs) {
        foreach ($jobs as $j) {
            $view_url  = admin_url('admin.php?page=pm-leads-jobs&job_id=' . intval($j->ID));
            $from      = get_post_meta($j->ID, 'current_postcode', true);
            $to        = get_post_meta($j->ID, 'new_postcode', true);
            $beds      = get_post_meta($j->ID, 'bedrooms_new', true);
            $purchases = get_post_meta($j->ID, 'purchase_count', true);
            $status_terms = wp_get_post_terms($j->ID, 'pm_job_status', ['fields' => 'names']);
            $status       = $status_terms ? implode(', ', $status_terms) : 'available';

            echo '<tr>';
            echo '<td><a href="' . esc_url($view_url) . '">#' . intval($j->ID) . '</a></td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($from) . '</a></td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($to) . '</a></td>';
            echo '<td>' . esc_html($beds) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($purchases !== '' ? intval($purchases) : 0) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No jobs yet.</td></tr>';
    }
    echo '</tbody></table></div>';
}


