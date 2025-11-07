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
