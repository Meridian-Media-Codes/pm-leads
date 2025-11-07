<?php
if ( ! defined('ABSPATH') ) exit;

function pm_leads_register_role() {
    // Base caps for vendors
    $caps = [
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
        'list_users' => false,
    ];
    add_role('pm_vendor', __('Vendor', 'pm-leads'), $caps);
}

// Ensure role exists on every init in case it was removed
add_action('init', function () {
    if (! get_role('pm_vendor')) {
        pm_leads_register_role();
    }
});
