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
    $status = in_array($status, ['pending','approved','declined'], true) ? $status : 'pending';
    update_user_meta($user_id, 'pm_vendor_status', $status);
    return $status;
}
function pm_vendor_is_approved($user_id) {
    return pm_vendor_get_status($user_id) === 'approved';
}
