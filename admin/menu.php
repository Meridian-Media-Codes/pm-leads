<?php
if ( ! defined('ABSPATH') ) exit;

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
});

function pm_leads_render_dashboard() {
    if (! current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>PM Leads</h1>';
    echo '<p>Overview will go here. Totals, recent activity.</p>';
    echo '</div>';
}

function pm_leads_render_vendors() {
    if (! current_user_can('manage_options')) return;
    $vendors = get_users(['role' => 'pm_vendor', 'number' => 200]);
    echo '<div class="wrap"><h1>Vendors</h1>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Company/User</th><th>Email</th><th>Credits</th><th>Registered</th><th>Profile</th>';
    echo '</tr></thead><tbody>';
    if ($vendors) {
        foreach ($vendors as $v) {
            $credits = get_user_meta($v->ID, 'pm_credit_balance', true);
            $credits = $credits === '' ? 0 : intval($credits);
            $registered = esc_html($v->user_registered);
            $profile_url = esc_url(get_edit_user_link($v->ID));
            echo '<tr>';
            echo '<td>' . esc_html($v->display_name) . '</td>';
            echo '<td>' . esc_html($v->user_email) . '</td>';
            echo '<td>' . esc_html($credits) . '</td>';
            echo '<td>' . $registered . '</td>';
            echo '<td><a href="' . $profile_url . '">Open</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No vendors found.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function pm_leads_render_jobs() {
    if (! current_user_can('manage_options')) return;

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
            echo '<td>' . esc_html($purchases !== '' ? intval($purchases) : 0) . '</td>';

            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No jobs yet.</td></tr>';
    }

    echo '</tbody></table></div>';
}


function pm_leads_render_settings() {
    if (! current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('pm_leads_options_group');
    do_settings_sections('pm_leads_settings');
    submit_button();
    echo '</form></div>';
}
