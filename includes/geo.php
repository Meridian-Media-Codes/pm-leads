<?php
if (!defined('ABSPATH')) exit;

/**
 * Geocode a UK postcode using Google Maps API
 */
function pm_geocode_postcode($postcode) {

    $postcode = trim($postcode);
    if (!$postcode) return false;

    $opts = pm_leads_get_options();
    $api  = isset($opts['google_api_key']) ? $opts['google_api_key'] : '';

    if (!$api) return false;

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($postcode) . '&key=' . $api;

    $resp = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($resp)) return false;

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!isset($data['results'][0]['geometry']['location'])) return false;

    $loc = $data['results'][0]['geometry']['location'];

    return [
        'lat' => floatval($loc['lat']),
        'lng' => floatval($loc['lng']),
    ];
}

/* Helper kept as-is */
function pm_leads_geocode($postcode) {
    return pm_geocode_postcode($postcode);
}

/**
 * Update vendor lat/lng when postcode updates
 */
function pm_vendor_maybe_geocode($user_id, $postcode) {
    $coords = pm_geocode_postcode($postcode);
    if (!$coords) return;

    update_user_meta($user_id, 'pm_vendor_lat', $coords['lat']);
    update_user_meta($user_id, 'pm_vendor_lng', $coords['lng']);
}

/**
 * Update job lat/lng
 *
 * Backwards compatible:
 * - If $prefix is null, write to pm_job_lat / pm_job_lng (your current keys).
 * - If $prefix is provided (e.g. 'pm_job_from' or 'pm_job_to'), also write to those keys.
 */
function pm_job_geocode($job_id, $postcode, $prefix = null) {
    $coords = pm_geocode_postcode($postcode);
    if (!$coords) {
        error_log("PM GEO: no coords for job {$job_id} / " . $postcode);
        return;
    }

    // Always maintain legacy keys
    update_post_meta($job_id, 'pm_job_lat', $coords['lat']);
    update_post_meta($job_id, 'pm_job_lng', $coords['lng']);

    // Optional namespaced keys
    if ($prefix) {
        $lat_key = $prefix . '_lat';
        $lng_key = $prefix . '_lng';

        update_post_meta($job_id, $lat_key, $coords['lat']);
        update_post_meta($job_id, $lng_key, $coords['lng']);

        // DEBUG: read back to confirm
        $lat_now = get_post_meta($job_id, $lat_key, true);
        $lng_now = get_post_meta($job_id, $lng_key, true);
        error_log("PM GEO: wrote {$lat_key}={$lat_now}, {$lng_key}={$lng_now} for job {$job_id}");
    } else {
        error_log("PM GEO: no prefix provided for job {$job_id}");
    }
}



/**
 * Calculate distance (Haversine) in miles
 */
function pm_leads_distance_mi($lat1, $lng1, $lat2, $lng2) {

    $earth = 3958.8; // miles

    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);
    $lng1 = deg2rad($lng1);
    $lng2 = deg2rad($lng2);

    $d = 2 * asin(
        sqrt(
            pow(sin(($lat1 - $lat2) / 2), 2) +
            cos($lat1) * cos($lat2) *
            pow(sin(($lng1 - $lng2) / 2), 2)
        )
    );

    return $earth * $d;
}

/**
 * Get vendors within given radius (miles)
 */
function pm_get_nearby_vendors($lat, $lng, $radius) {

    $users = get_users(['role' => 'pm_vendor']);
    $nearby = [];

    foreach ($users as $u) {
        $v_lat = get_user_meta($u->ID, 'pm_vendor_lat', true);
        $v_lng = get_user_meta($u->ID, 'pm_vendor_lng', true);
        if (!$v_lat || !$v_lng) continue;

        $dist = pm_leads_distance_mi($lat, $lng, $v_lat, $v_lng);
        if ($dist <= $radius) {
            $nearby[] = [
                'user_id'  => $u->ID,
                'distance' => $dist
            ];
        }
    }

    usort($nearby, function ($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    return $nearby;
}
