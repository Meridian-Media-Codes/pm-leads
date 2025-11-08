<?php
if (!defined('ABSPATH')) exit;

/**
 * Return list of nearby, unpurchased, unsold jobs
 */
function pm_vendor_get_available_leads($user_id) {

    $opts   = pm_leads_get_options();
    $radius = isset($opts['default_radius']) ? intval($opts['default_radius']) : 50;

    $v_lat = get_user_meta($user_id, 'pm_vendor_lat', true);
    $v_lng = get_user_meta($user_id, 'pm_vendor_lng', true);

    if (!$v_lat || !$v_lng) return [];

    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $out = [];

    foreach ($jobs as $j) {

        $job_id = $j->ID;

        // Skip purchased
        $purchased = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($purchased)) $purchased = [];
        if (in_array($user_id, $purchased)) continue;

        // Skip declined
        $declined = get_user_meta($user_id, 'pm_declined_jobs', true);
        if (!is_array($declined)) $declined = [];
        if (in_array($job_id, $declined)) continue;

        // Skip sold out
        $limit     = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
        $purchases = intval(get_post_meta($job_id, 'purchase_count', true));
        if ($purchases >= $limit) continue;

        // Distance from vendor → origin
        $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
        $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);
        $to_lat   = get_post_meta($job_id, 'pm_job_to_lat', true);
        $to_lng   = get_post_meta($job_id, 'pm_job_to_lng', true);

        if (!$from_lat || !$from_lng) continue;
        if (!$to_lat   || !$to_lng)   continue;

        $dist_to_origin = pm_leads_distance_mi($v_lat, $v_lng, $from_lat, $from_lng);
        if ($dist_to_origin > $radius) continue;

        $job_total_dist = pm_leads_distance_mi($from_lat, $from_lng, $to_lat, $to_lng);

        $out[] = [
            'job_id'         => $job_id,
            'from'           => get_post_meta($job_id, 'current_postcode', true),
            'to'             => get_post_meta($job_id, 'new_postcode', true),
            'dist_to_origin' => $dist_to_origin,
            'job_distance'   => $job_total_dist,
            'purchases'      => $purchases,
        ];
    }

    // Sort by nearest origin
    usort($out, function ($a, $b) {
        return $a['dist_to_origin'] <=> $b['dist_to_origin'];
    });

    return $out;
}


/**
 * Jobs vendor already purchased
 */
function pm_vendor_get_purchased_leads($user_id) {

    return get_posts([
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
}



/**
 * Dashboard Shortcode
 */
add_shortcode('pm_vendor_dashboard', function () {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $uid     = get_current_user_id();
    $balance = intval(get_user_meta($uid, 'pm_credit_balance', true));
    $jobs    = pm_vendor_get_available_leads($uid);

    ob_start();

    echo '<div class="pm-dashboard">';
    echo '<h2>Available Leads</h2>';
    echo '<p>Credits: <strong>' . intval($balance) . '</strong></p>';

    if (empty($jobs)) {
        echo '<p>No leads available in your area.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    echo '<table class="pm-table">';
    echo '<thead><tr>';
    echo '<th>From</th>';
    echo '<th>To</th>';
    echo '<th>Dist to origin (mi)</th>';
    echo '<th>Job distance (mi)</th>';
    echo '<th>Purchases</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($jobs as $row) {

        $job_id   = $row['job_id'];
        $product  = get_post_meta($job_id, '_pm_lead_product_id', true);

        echo '<tr>';
        echo '<td>' . esc_html($row['from']) . '</td>';
        echo '<td>' . esc_html($row['to'])   . '</td>';
        echo '<td>' . number_format($row['dist_to_origin'], 1) . '</td>';
        echo '<td>' . number_format($row['job_distance'], 1)   . '</td>';
        echo '<td>' . intval($row['purchases']) . '/5</td>';

        echo '<td>';

        // Buy with credits
        if ($balance > 0) {
            echo '<a href="' . esc_url(add_query_arg([
                'pm_buy_lead' => $job_id
            ])) . '" class="button">Buy with credits</a> ';
        }

        // Buy now (WC)
        if ($product) {
            echo '<a href="' . esc_url(wc_get_cart_url() . '?add-to-cart=' . $product) . '" class="button">Buy Now</a>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    return ob_get_clean();
});
