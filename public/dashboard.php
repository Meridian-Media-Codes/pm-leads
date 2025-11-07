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

function pm_vendor_dashboard_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>Please log in to view your vendor dashboard.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('pm_vendor', (array)$user->roles, true)) {
        return '<p>You must be a vendor to view this page.</p>';
    }

    // Fetch leads
    $available = pm_vendor_get_available_leads($user->ID);
    $purchased = pm_vendor_get_purchased_leads($user->ID);

    ob_start();

    ?>
    <div class="pm-vendor-dashboard">

        <?php
        // Optional notices
        if (isset($_GET['pm_notice'])) {
            $ok = isset($_GET['pm_ok']) && $_GET['pm_ok'] === '1';
            echo '<div class="' . ($ok ? 'updated' : 'error') . '"><p>' . esc_html(wp_unslash($_GET['pm_notice'])) . '</p></div>';
        }

        // Credit balance display
        $bal = intval(get_user_meta($user->ID, 'pm_credit_balance', true));
        echo '<p><strong>Your credits:</strong> ' . $bal . '</p>';
        ?>

        <h2>New Leads</h2>
        <?php if (!$available) : ?>
            <p>No new leads available.</p>
        <?php else: ?>
            <table class="widefat pm-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Bedrooms</th>
                        <th>Distance</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($available as $row) :
                    $id = $row['job_id'];
                    $dist = round($row['distance'], 1);

                    $from = get_post_meta($id, 'current_postcode', true);
                    $to   = get_post_meta($id, 'new_postcode', true);
                    $bed  = get_post_meta($id, 'bedrooms_new', true);

                    $nonce = wp_create_nonce('pm_lead_' . $id);
                    $credit_url = admin_url('admin-post.php?action=pm_buy_lead_credit&job_id=' . $id . '&_wpnonce=' . $nonce);
                    $cash_url   = admin_url('admin-post.php?action=pm_buy_lead_cash&job_id=' . $id . '&_wpnonce=' . $nonce);
                    $decl_url   = admin_url('admin-post.php?action=pm_decline_lead&job_id=' . $id . '&_wpnonce=' . $nonce);
                ?>
                    <tr>
                        <td><?php echo esc_html($from); ?></td>
                        <td><?php echo esc_html($to); ?></td>
                        <td><?php echo esc_html($bed); ?></td>
                        <td><?php echo esc_html($dist . ' mi'); ?></td>
                        <td>
                            <a class="button" href="<?php echo esc_url($credit_url); ?>">Use 1 credit</a>
                            <a class="button" href="<?php echo esc_url($cash_url); ?>">Buy now</a>
                            <a class="button button-secondary" href="<?php echo esc_url($decl_url); ?>">Decline</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>


        <h2 style="margin-top:40px;">Purchased Leads</h2>
        <?php if (!$purchased) : ?>
            <p>No purchased leads.</p>
        <?php else: ?>
            <table class="widefat pm-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Bedrooms</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($purchased as $j) :
                    $from = get_post_meta($j->ID, 'current_postcode', true);
                    $to   = get_post_meta($j->ID, 'new_postcode', true);
                    $bed  = get_post_meta($j->ID, 'bedrooms_new', true);
                    $msg  = get_post_meta($j->ID, 'customer_message', true);
                ?>
                    <tr>
                        <td><?php echo esc_html($from); ?></td>
                        <td><?php echo esc_html($to); ?></td>
                        <td><?php echo esc_html($bed); ?></td>
                        <td><?php echo esc_html($msg); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
    <?php

    return ob_get_clean();
}
