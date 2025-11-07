<?php
if (!defined('ABSPATH')) exit;

/**
 * Geocode helper for vendors + jobs
 */

// Simple example only; replace with Google later
function pm_vendor_maybe_geocode($user_id, $postcode) {

    $postcode = trim($postcode);
    if (!$postcode) return;

    $coords = pm_geocode_postcode($postcode);
    if (!$coords) return;

    update_user_meta($user_id, 'pm_vendor_lat', $coords['lat']);
    update_user_meta($user_id, 'pm_vendor_lng', $coords['lng']);
}

// Dummy placeholder
function pm_geocode_postcode($postcode) {

    // TODO: replace with Google Maps
    // For now return fake coords so logic can continue

    return [
        'lat' => 53.8175,
        'lng' => -3.0357
    ];
}
