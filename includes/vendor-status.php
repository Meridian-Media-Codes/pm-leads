<?php
if (!defined('ABSPATH')) exit;

/**
 * Vendor status helpers
 * statuses: pending | approved | declined
 */
function pm_vendor_get_status($user_id) {
    $s = get_user_meta($user_id, 'pm_vendor_status', true);
    return $s ? $s : 'pending';
}
function pm_vendor_set_status($user_id, $status) {
    $status = in_array($status, ['pending', 'approved', 'declined'], true) ? $status : 'pending';

    $old = get_user_meta($user_id, 'pm_vendor_status', true);
    update_user_meta($user_id, 'pm_vendor_status', $status);

    // Fire events when status changes
    if ($old !== $status) {
        switch ($status) {
            case 'approved':
                do_action('pm_leads_vendor_approved', $user_id);
                break;
            case 'declined':
                do_action('pm_leads_vendor_declined', $user_id);
                break;
            case 'pending':
                do_action('pm_leads_vendor_pending', $user_id);
                break;
        }
    }

    return $status;
}

function pm_vendor_is_approved($user_id) {
    return pm_vendor_get_status($user_id) === 'approved';
}
