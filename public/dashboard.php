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

        // Skip purchased by this vendor
        $purchased = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($purchased)) $purchased = [];
        if (in_array($user_id, $purchased, true)) continue;

        // Skip vendor declined list
        $declined = get_user_meta($user_id, 'pm_declined_jobs', true);
        if (!is_array($declined)) $declined = [];
        if (in_array($job_id, $declined, true)) continue;

        // Sold-out?
        $limit     = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
        $purchases = intval(get_post_meta($job_id, 'purchase_count', true));
        if ($purchases >= $limit) continue;

        // Distance: vendor -> FROM
        $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
        $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);
        $to_lat   = get_post_meta($job_id, 'pm_job_to_lat', true);
        $to_lng   = get_post_meta($job_id, 'pm_job_to_lng', true);

        if (!$from_lat || !$from_lng) continue;
        if (!$to_lat   || !$to_lng) continue;

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
 * Handle "Buy with credits"
 */
add_action('template_redirect', function () {

    if (empty($_GET['pm_buy_lead'])) return;

    if (!is_user_logged_in()) {
        wp_safe_redirect(
            wp_login_url(
                add_query_arg([], remove_query_arg(['pm_buy_lead','_wpnonce','pm_msg']))
            )
        );
        exit;
    }

    $job_id = absint($_GET['pm_buy_lead']);
    $nonce  = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    $back   = wp_get_referer() ?: home_url('/');

    if (!$job_id || !wp_verify_nonce($nonce, 'pm_buy_lead_' . $job_id)) {
        wp_safe_redirect(add_query_arg('pm_msg', 'invalid', $back));
        exit;
    }

    $user_id = get_current_user_id();
    $opts    = pm_leads_get_options();

    // Already purchased?
    $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
    if (!is_array($buyers)) $buyers = [];
    if (in_array($user_id, $buyers, true)) {
        wp_safe_redirect(add_query_arg('pm_msg', 'already', $back));
        exit;
    }

    // Limit
    $limit = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
    $count = intval(get_post_meta($job_id, 'purchase_count', true));
    if ($count >= $limit) {
        wp_safe_redirect(add_query_arg('pm_msg', 'soldout', $back));
        exit;
    }

    // Credits
    $bal = intval(get_user_meta($user_id, 'pm_credit_balance', true));
    if ($bal < 1) {
        wp_safe_redirect(add_query_arg('pm_msg', 'nobal', $back));
        exit;
    }

    update_user_meta($user_id, 'pm_credit_balance', max(0, $bal - 1));

    // Mark purchased
    pm_leads_mark_vendor_bought($job_id, $user_id);
    pm_leads_inc_purchase_count($job_id);


    // Sync WC stock
    $product_id = intval(get_post_meta($job_id, '_pm_lead_product_id', true));
    if ($product_id && function_exists('wc_get_product')) {
        $product = wc_get_product($product_id);
        if ($product && $product->managing_stock()) {
            $current_stock = (int)$product->get_stock_quantity();
            $new_stock     = max(0, $current_stock - 1);
            wc_update_product_stock($product, $new_stock, 'set');
        }
    }

    // Hook for email
    do_action('pm_lead_purchased_with_credits', $job_id, $user_id);

    wp_safe_redirect(add_query_arg('pm_msg', 'ok', $back));
    exit;
});

if (!defined('ABSPATH')) exit;

/**
 * Vendor available leads
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
        $purchased = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($purchased)) $purchased = [];
        if (in_array($user_id, $purchased, true)) continue;

        $limit     = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
        $purchases = intval(get_post_meta($job_id, 'purchase_count', true));
        if ($purchases >= $limit) continue;

        $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
        $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);
        $to_lat   = get_post_meta($job_id, 'pm_job_to_lat', true);
        $to_lng   = get_post_meta($job_id, 'pm_job_to_lng', true);
        if (!$from_lat || !$from_lng || !$to_lat || !$to_lng) continue;

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

    usort($out, fn($a, $b) => $a['dist_to_origin'] <=> $b['dist_to_origin']);
    return $out;
}

/**
 * Vendor purchased leads
 */
function pm_vendor_get_purchased_leads($user_id) {
    return get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            ['key' => 'pm_purchased_by', 'value' => $user_id, 'compare' => 'LIKE']
        ]
    ]);
}

/**
 * Buy with credits
 */
add_action('template_redirect', function () {
    if (empty($_GET['pm_buy_lead'])) return;
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url());
        exit;
    }

    $job_id = absint($_GET['pm_buy_lead']);
    $nonce  = sanitize_text_field($_GET['_wpnonce'] ?? '');
    $back   = wp_get_referer() ?: home_url('/');

    if (!$job_id || !wp_verify_nonce($nonce, 'pm_buy_lead_' . $job_id)) {
        wp_safe_redirect(add_query_arg('pm_msg', 'invalid', $back));
        exit;
    }

    $user_id = get_current_user_id();
    $opts    = pm_leads_get_options();

    $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
    if (!is_array($buyers)) $buyers = [];
    if (in_array($user_id, $buyers, true)) {
        wp_safe_redirect(add_query_arg('pm_msg', 'already', $back));
        exit;
    }

    $limit = intval($opts['purchase_limit'] ?? 5);
    $count = intval(get_post_meta($job_id, 'purchase_count', true));
    if ($count >= $limit) {
        wp_safe_redirect(add_query_arg('pm_msg', 'soldout', $back));
        exit;
    }

    $bal = intval(get_user_meta($user_id, 'pm_credit_balance', true));
    if ($bal < 1) {
        wp_safe_redirect(add_query_arg('pm_msg', 'nobal', $back));
        exit;
    }

    update_user_meta($user_id, 'pm_credit_balance', max(0, $bal - 1));
    pm_leads_mark_vendor_bought($job_id, $user_id);
    pm_leads_inc_purchase_count($job_id);
    do_action('pm_lead_purchased_with_credits', $job_id, $user_id);

    wp_safe_redirect(add_query_arg('pm_msg', 'ok', $back));
    exit;
});

/**
 * Shortcode: Vendor dashboard
 */
add_shortcode('pm_vendor_dashboard', function () {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $uid       = get_current_user_id();
    $balance   = intval(get_user_meta($uid, 'pm_credit_balance', true));
    $available = pm_vendor_get_available_leads($uid);
    $purchased = pm_vendor_get_purchased_leads($uid);

    $msg     = sanitize_text_field($_GET['pm_msg'] ?? '');
    $notices = [
        'ok'      => 'Lead purchased with credits.',
        'nobal'   => 'You do not have enough credits.',
        'soldout' => 'This lead is no longer available.',
        'already' => 'You already own this lead.',
        'invalid' => 'Invalid request.',
    ];

    $credit_prices = get_option('pm_leads_credit_prices', [
        'price_1'  => 2,
        'price_5'  => 8,
        'price_10' => 15,
    ]);

    ob_start();
    ?>
    <div class="pm-dashboard">

        <h2 class="pm-title">Vendor dashboard</h2>
        <div class="pm-card">
            <p class="pm-credit">Credits: <strong><?php echo $balance; ?></strong></p>
            <button class="button pm-open-credit-modal">Purchase credits</button>
        </div>

        <?php if ($msg && isset($notices[$msg])): ?>
            <div class="pm-alert"><?php echo esc_html($notices[$msg]); ?></div>
        <?php endif; ?>

        <div class="pm-card">
            <h3>Available leads</h3>
            <?php if (empty($available)): ?>
                <p>No leads available in your area.</p>
            <?php else: ?>
                <table class="pm-table">
                    <thead><tr>
                        <th>ID</th><th>From</th><th>To</th><th>Dist to origin (mi)</th>
                        <th>Job distance (mi)</th><th>Purchases</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($available as $row): 
                        $job_id  = $row['job_id'];
                        $product = intval(get_post_meta($job_id, '_pm_lead_product_id', true)); ?>
                        <tr>
                            <td>#<?php echo $job_id; ?></td>
                            <td><?php echo esc_html($row['from']); ?></td>
                            <td><?php echo esc_html($row['to']); ?></td>
                            <td><?php echo number_format($row['dist_to_origin'], 1); ?></td>
                            <td><?php echo number_format($row['job_distance'], 1); ?></td>
                            <td><?php echo intval($row['purchases']); ?>/5</td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg([
                                    'pm_buy_lead' => $job_id,
                                    '_wpnonce' => wp_create_nonce('pm_buy_lead_' . $job_id),
                                ])); ?>" class="button pm-btn">Buy with 1 credit</a>
                                <?php if ($product): ?>
                                    <a href="<?php echo esc_url(get_permalink($product)); ?>" class="button pm-btn-outline">Buy now</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="pm-card">
            <h3>Purchased leads</h3>
            <?php if (empty($purchased)): ?>
                <p>You have no purchased leads yet.</p>
            <?php else: ?>
                <table class="pm-table">
                    <thead><tr><th>ID</th><th>From</th><th>To</th></tr></thead>
                    <tbody>
                    <?php foreach ($purchased as $j):
                        $job_id = $j->ID;
                        $details = [
                            'Customer Name' => get_post_meta($job_id, 'customer_name', true),
                            'Customer Email' => get_post_meta($job_id, 'customer_email', true),
                            'Customer Phone' => get_post_meta($job_id, 'customer_phone', true),
                            'Message' => get_post_meta($job_id, 'customer_message', true),
                            'From Postcode' => get_post_meta($job_id, 'current_postcode', true),
                            'To Postcode' => get_post_meta($job_id, 'new_postcode', true),
                        ];
                        $json = wp_json_encode($details); ?>
                        <tr>
                            <td><a href="#" class="pm-view-job" data-payload="<?php echo esc_attr($json); ?>">#<?php echo $job_id; ?></a></td>
                            <td><?php echo esc_html($details['From Postcode']); ?></td>
                            <td><?php echo esc_html($details['To Postcode']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Job modal -->
    <div id="pm-job-modal" class="pm-modal">
        <div class="pm-modal-content">
            <span class="pm-close">&times;</span>
            <h3>Lead details</h3>
            <div id="pm-job-fields"></div>
        </div>
    </div>

    <!-- Credit modal -->
    <div id="pm-credit-modal" class="pm-modal">
        <div class="pm-modal-content">
            <span class="pm-close">&times;</span>
            <h3>Purchase credits</h3>
            <p>Select a credit bundle</p>
            <p><a class="button pm-buy-credit" data-qty="1">Buy 1 credit (£<?php echo $credit_prices['price_1']; ?>)</a></p>
            <p><a class="button pm-buy-credit" data-qty="5">Buy 5 credits (£<?php echo $credit_prices['price_5']; ?>)</a></p>
            <p><a class="button pm-buy-credit" data-qty="10">Buy 10 credits (£<?php echo $credit_prices['price_10']; ?>)</a></p>
        </div>
    </div>

    <script>
    document.addEventListener('click', function(e){
        if(e.target.classList.contains('pm-view-job')){
            e.preventDefault();
            const data = JSON.parse(e.target.dataset.payload);
            let html='';
            for(const label in data){
                html += `<p><strong>${label}:</strong> ${data[label]??''}</p>`;
            }
            document.getElementById('pm-job-fields').innerHTML = html;
            document.getElementById('pm-job-modal').style.display='block';
        }
        if(e.target.classList.contains('pm-close') || e.target.id==='pm-job-modal' || e.target.id==='pm-credit-modal'){
            document.getElementById('pm-job-modal').style.display='none';
            document.getElementById('pm-credit-modal').style.display='none';
        }
        if(e.target.classList.contains('pm-open-credit-modal')){
            document.getElementById('pm-credit-modal').style.display='block';
        }
        if(e.target.classList.contains('pm-buy-credit')){
            const qty = e.target.dataset.qty;
            window.location = "<?php echo home_url('/'); ?>?pm_buy_credits="+qty;
        }
    });
    </script>
    <?php
    return ob_get_clean();
});

