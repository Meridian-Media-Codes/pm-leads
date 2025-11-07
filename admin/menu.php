<?php
if (!defined('ABSPATH')) exit;

/*
|--------------------------------------------------------------------------
| Admin Menu
|--------------------------------------------------------------------------
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

    add_submenu_page('pm-leads', __('Dashboard', 'pm-leads'), __('Dashboard', 'pm-leads'), 'manage_options', 'pm-leads', 'pm_leads_render_dashboard');
    add_submenu_page('pm-leads', __('Vendors', 'pm-leads'), __('Vendors', 'pm-leads'), 'manage_options', 'pm-leads-vendors', 'pm_leads_render_vendors');
    add_submenu_page('pm-leads', __('Jobs', 'pm-leads'), __('Jobs', 'pm-leads'), 'manage_options', 'pm-leads-jobs', 'pm_leads_render_jobs');
    add_submenu_page('pm-leads', __('Settings', 'pm-leads'), __('Settings', 'pm-leads'), 'manage_options', 'pm-leads-settings', 'pm_leads_render_settings');

    /* Hidden vendor page */
    add_submenu_page(
        null,
        __('Vendor Detail', 'pm-leads'),
        __('Vendor Detail', 'pm-leads'),
        'manage_options',
        'pm-leads-vendor-detail',
        'pm_leads_render_vendor_detail'
    );

});


/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/
function pm_leads_render_dashboard() {
    echo '<div class="wrap"><h1>PM Leads</h1>';
    echo '<p>Overview coming soon.</p>';
    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| Vendors List
|--------------------------------------------------------------------------
*/
function pm_leads_render_vendors() {

    $vendors = get_users(['role' => 'pm_vendor', 'number' => 200]);

    echo '<div class="wrap"><h1>Vendors</h1>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Company</th><th>Email</th><th>Credits</th><th>Registered</th><th>View</th>';
    echo '</tr></thead><tbody>';

    if ($vendors) {
        foreach ($vendors as $v) {

            $credits = intval(get_user_meta($v->ID, 'pm_credit_balance', true));
            $company = get_user_meta($v->ID, 'company_name', true);
            $company = $company ?: $v->display_name;

            $detail_url = admin_url('admin.php?page=pm-leads-vendor-detail&vendor=' . $v->ID);

            echo '<tr>';
            echo '<td>' . esc_html($company) . '</td>';
            echo '<td>' . esc_html($v->user_email) . '</td>';
            echo '<td>' . esc_html($credits) . '</td>';
            echo '<td>' . esc_html($v->user_registered) . '</td>';
            echo '<td><a href="' . esc_url($detail_url) . '">View</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No vendors found.</td></tr>';
    }

    echo '</tbody></table></div>';
}


/*
|--------------------------------------------------------------------------
| Vendor Detail Page
|--------------------------------------------------------------------------
*/
function pm_leads_render_vendor_detail() {

    if (!current_user_can('manage_options')) return;

    $vendor_id = isset($_GET['vendor']) ? intval($_GET['vendor']) : 0;
    $vendor    = get_user_by('ID', $vendor_id);

    if (!$vendor) {
        echo '<div class="wrap"><h1>Vendor Not Found</h1></div>';
        return;
    }

    // Save credits if POSTed
    if (!empty($_POST['pm_vendor_update']) && check_admin_referer('pm_vendor_update_' . $vendor_id)) {
        $new_credits = intval($_POST['pm_credit_balance']);
        update_user_meta($vendor_id, 'pm_credit_balance', $new_credits);
        echo '<div class="updated"><p>Credits updated.</p></div>';
    }

    // Pull meta
    $meta = [
        'company_name'      => get_user_meta($vendor_id, 'company_name', true),
        'first_name'        => get_user_meta($vendor_id, 'first_name', true),
        'last_name'         => get_user_meta($vendor_id, 'last_name', true),
        'vendor_email'      => $vendor->user_email,
        'contact_number'    => get_user_meta($vendor_id, 'contact_number', true),
        'business_postcode' => get_user_meta($vendor_id, 'business_postcode', true),
        'service_radius'    => get_user_meta($vendor_id, 'service_radius', true),
        'years_in_business' => get_user_meta($vendor_id, 'years_in_business', true),
        'insurance_docs'    => get_user_meta($vendor_id, 'insurance_docs', true),
        'credits'           => intval(get_user_meta($vendor_id, 'pm_credit_balance', true)),
        'registered'        => $vendor->user_registered,
    ];

    echo '<div class="wrap"><h1>Vendor Profile</h1>';

    echo '<table class="widefat striped">';
    foreach ($meta as $label => $val) {

        echo '<tr>';
        echo '<th style="width:240px;">' . esc_html(ucwords(str_replace('_', ' ', $label))) . '</th>';
        if ($label === 'insurance_docs' && $val) {
            echo '<td><a href="' . esc_url($val) . '" target="_blank">View File</a></td>';
        } else {
            echo '<td>' . esc_html($val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';

    /* Edit credits */
    echo '<h2>Edit Credits</h2>';
    echo '<form method="post">';
    wp_nonce_field('pm_vendor_update_' . $vendor_id);
    echo '<input type="number" name="pm_credit_balance" value="' . esc_attr($meta['credits']) . '" />';
    echo '<p><button type="submit" name="pm_vendor_update" class="button button-primary">Save</button></p>';
    echo '</form>';

    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| Jobs
|--------------------------------------------------------------------------
*/
function pm_leads_render_jobs() {

    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => 50,
        'post_status'    => 'any',
    ]);

    echo '<div class="wrap"><h1>Jobs</h1>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>ID</th><th>From</th><th>To</th><th>Bedrooms</th><th>Status</th><th>Purchases</th>';
    echo '</tr></thead><tbody>';

    if ($jobs) {
        foreach ($jobs as $j) {

            $edit_url  = admin_url("post.php?post={$j->ID}&action=edit");

            $from      = get_post_meta($j->ID, 'current_postcode', true);
            $to        = get_post_meta($j->ID, 'new_postcode', true);
            $beds      = get_post_meta($j->ID, 'bedrooms_new', true);
            $purchases = get_post_meta($j->ID, 'purchase_count', true);

            $status_terms = wp_get_post_terms($j->ID, 'pm_job_status', ['fields' => 'names']);
            $status       = $status_terms ? implode(', ', $status_terms) : 'available';

            echo '<tr>';
            echo '<td><a href="' . esc_url($edit_url) . '">#' . intval($j->ID) . '</a></td>';
            echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html($from) . '</a></td>';
            echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html($to) . '</a></td>';
            echo '<td>' . esc_html($beds) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($purchases ?: 0) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No jobs yet.</td></tr>';
    }

    echo '</tbody></table></div>';
}


/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/
function pm_leads_render_settings() {

    echo '<div class="wrap"><h1>Settings</h1>';
    echo '<form method="post" action="options.php">';

    settings_fields('pm_leads_options_group');
    do_settings_sections('pm_leads_settings');
    submit_button();

    echo '</form></div>';
}
