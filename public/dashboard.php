<?php
if (!defined('ABSPATH')) exit;

/**
 * Vendor lead dashboard helpers
 */

function pm_vendor_get_available_leads($user_id) {

    $opts = pm_leads_get_options();
    $radius = isset($opts['default_radius']) ? intval($opts['default_radius']) : 50;

    $v_lat = get_user_meta($user_id, 'pm_vendor_lat', true);
    $v_lng = get_user_meta($user_id, 'pm_vendor_lng', true);

    if (!$v_lat || !$v_lng) return [];

    // Get all jobs
    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $out = [];

    foreach ($jobs as $j) {

        // Skip if vendor purchased or declined already
        $purchased = get_post_meta($j->ID, 'pm_purchased_by', true);
        $declined  = get_user_meta($user_id, 'pm_declined_jobs', true);
        if (!is_array($declined)) $declined = [];

        if (is_array($purchased) && in_array($user_id, $purchased)) continue;
        if (in_array($j->ID, $declined)) continue;

        // Skip if sold out
        $limit     = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
        $purchases = intval(get_post_meta($j->ID, 'purchase_count', true));
        if ($purchases >= $limit) continue;

        // Check distance
        $j_lat = get_post_meta($j->ID, 'pm_job_lat', true);
        $j_lng = get_post_meta($j->ID, 'pm_job_lng', true);

        if (!$j_lat || !$j_lng) continue;

        $distance = pm_leads_distance_mi($v_lat, $v_lng, $j_lat, $j_lng);

        if ($distance <= $radius) {
            $out[] = [
                'job_id'   => $j->ID,
                'distance' => $distance
            ];
        }
    }

    // Sort by nearest
    usort($out, function ($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    return $out;
}


function pm_vendor_get_purchased_leads($user_id) {

    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => 'pm_purchased_by',
                'value'   => $user_id,
                'compare' => 'LIKE'
            ]
        ]
    ]);

    return $jobs;
}


/**
 * Dashboard Shortcode Output
 */

