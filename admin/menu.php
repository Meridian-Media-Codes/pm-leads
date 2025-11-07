<?php
if (!defined('ABSPATH')) exit;

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

    // NEW — single-job view
    add_submenu_page('pm-leads', 'Job Details', 'Job Details', 'manage_options', 'pm-leads-job', 'pm_leads_render_single_job');

    add_submenu_page('pm-leads', __('Settings', 'pm-leads'), __('Settings', 'pm-leads'), 'manage_options', 'pm-leads-settings', 'pm_leads_render_settings');
});


function pm_leads_render_dashboard() {
    echo '<div class="wrap"><h1>PM Leads</h1><p>Overview will go here.</p></div>';
}

function pm_leads_render_vendors() {
    require_once PM_LEADS_DIR . 'admin/vendor-list.php';
}

function pm_leads_render_jobs() {

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

            $edit_url = admin_url("admin.php?page=pm-leads-job&job_id={$j->ID}");

            $from      = get_post_meta($j->ID, 'current_postcode', true);
            $to        = get_post_meta($j->ID, 'new_postcode', true);
            $beds      = get_post_meta($j->ID, 'bedrooms_new', true);
            $purchases = get_post_meta($j->ID, 'purchase_count', true);

            $status_terms = wp_get_post_terms($j->ID, 'pm_job_status', ['fields'=>'names']);
            $status       = $status_terms ? implode(', ', $status_terms) : 'available';

            echo '<tr>';
            echo '<td><a href="'.esc_url($edit_url).'">#'.$j->ID.'</a></td>';
            echo '<td><a href="'.esc_url($edit_url).'">'.esc_html($from).'</a></td>';
            echo '<td><a href="'.esc_url($edit_url).'">'.esc_html($to).'</a></td>';
            echo '<td>'.esc_html($beds).'</td>';
            echo '<td>'.esc_html($status).'</td>';
            echo '<td>'.esc_html($purchases ?: 0).'</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No jobs yet.</td></tr>';
    }

    echo '</tbody></table></div>';
}


/**
 * Render single job (custom UI)
 */
function pm_leads_render_single_job() {

    if (empty($_GET['job_id'])) {
        echo '<div class="wrap"><h1>No job selected</h1></div>';
        return;
    }

    $job_id = absint($_GET['job_id']);
    require PM_LEADS_DIR . 'admin/job-meta.php';
}


function pm_leads_render_settings() {
    echo '<div class="wrap"><h1>Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('pm_leads_options_group');
    do_settings_sections('pm_leads_settings');
    submit_button();
    echo '</form></div>';
}
