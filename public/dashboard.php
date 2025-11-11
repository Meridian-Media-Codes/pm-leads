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



/**
 * Shortcode: Vendor Dashboard
 */
add_shortcode('pm_vendor_dashboard', function () {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $uid       = get_current_user_id();
    $balance   = intval(get_user_meta($uid, 'pm_credit_balance', true));
    $available = pm_vendor_get_available_leads($uid);
    $purchased = pm_vendor_get_purchased_leads($uid);

    // Notices
    $msg     = isset($_GET['pm_msg']) ? sanitize_text_field($_GET['pm_msg']) : '';
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
    echo '<div class="pm-dashboard">';
    echo '<h2>Vendor Dashboard</h2>';
    echo '<p><strong>Credits:</strong> ' . intval($balance) . '</p>';

    if ($msg && isset($notices[$msg])) {
        echo '<div class="notice" style="margin:10px 0;padding:10px;border:1px solid #ccd0d4;background:#fff;">'
            . esc_html($notices[$msg]) . '</div>';
    }

    /* ✅ BUY CREDITS UI */
    echo '<hr>';
    echo '<h3>Buy Credits</h3>';
    echo '<button class="button pm-open-credit-modal">Purchase Credits</button>';



    

    ob_start();


    echo '<hr>';
    echo '<h3>Available Leads</h3>';

    if (empty($available)) {
        echo '<p>No leads available in your area.</p>';
    } else {
        echo '<table class="pm-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>From</th>';
        echo '<th>To</th>';
        echo '<th>Dist to origin (mi)</th>';
        echo '<th>Job distance (mi)</th>';
        echo '<th>Purchases</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($available as $row) {

            $job_id  = $row['job_id'];
            $product = intval(get_post_meta($job_id, '_pm_lead_product_id', true));

            echo '<tr>';
            echo '<td>#' . intval($job_id) . '</td>';
            echo '<td>' . esc_html($row['from']) . '</td>';
            echo '<td>' . esc_html($row['to'])   . '</td>';
            echo '<td>' . number_format((float)$row['dist_to_origin'], 1) . '</td>';
            echo '<td>' . number_format((float)$row['job_distance'], 1) . '</td>';
            echo '<td>' . intval($row['purchases']) . '/5</td>';

            echo '<td>';

            echo '<a href="' . esc_url(add_query_arg([
                'pm_buy_lead' => $job_id,
                '_wpnonce'    => wp_create_nonce('pm_buy_lead_' . $job_id),
            ])) . '" class="button">Buy with 1 credit</a> ';

            if ($product) {
                $product_url = get_permalink($product);
                echo '<a href="' . esc_url($product_url) . '" class="button">Buy now</a>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '<hr>';
    echo '<h3>Purchased Leads</h3>';

    if (empty($purchased)) {
        echo '<p>You have no purchased leads yet.</p>';
    } else {

        echo '<table class="pm-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>From</th>';
        echo '<th>To</th>';
        echo '</tr></thead><tbody>';

        foreach ($purchased as $j) {

            $job_id = $j->ID;

            $details = [
                'Customer Name'    => get_post_meta($job_id, 'customer_name', true),
                'Customer Email'   => get_post_meta($job_id, 'customer_email', true),
                'Customer Phone'   => get_post_meta($job_id, 'customer_phone', true),
                'Message'          => get_post_meta($job_id, 'customer_message', true),
                'From Postcode'    => get_post_meta($job_id, 'current_postcode', true),
                'From Address'     => get_post_meta($job_id, 'current_address', true),
                'Bedrooms (From)'  => get_post_meta($job_id, 'bedrooms_current', true),
                'To Postcode'      => get_post_meta($job_id, 'new_postcode', true),
                'To Address'       => get_post_meta($job_id, 'new_address', true),
                'Bedrooms (To)'    => get_post_meta($job_id, 'bedrooms_new', true),
            ];

            $json = wp_json_encode($details);

            echo '<tr>';
            echo '<td><a href="#" class="pm-view-job" data-payload="' . esc_attr($json) . '" data-id="' . intval($job_id) . '">#' . intval($job_id) . '</a></td>';
            echo '<td>' . esc_html($details['From Postcode']) . '</td>';
            echo '<td>' . esc_html($details['To Postcode'])   . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>'; // CLOSE pm-dashboard
?>

<!-- MODAL -->
<div id="pm-job-modal" class="pm-modal">
    <div class="pm-modal-content">
        <span class="pm-close">&times;</span>
        <h3>Lead Details</h3>
        <div id="pm-job-fields"></div>
    </div>
</div>

<style>
.pm-modal {
    display:none;
    position:fixed;
    z-index:9999;
    left:0; top:0;
    width:100%; height:100%;
    background:rgba(0,0,0,0.5);
}
.pm-modal-content {
    background:#fff;
    width:90%;
    max-width:500px;
    margin:80px auto;
    padding:20px;
    border-radius:6px;
}
.pm-close {
    float:right;
    font-size:22px;
    cursor:pointer;
}
#pm-job-fields p {
    margin:6px 0;
}
#pm-job-fields strong {
    display:inline-block;
    width:140px;
}
</style>

<script>
document.addEventListener('click', function(e){
    if(!e.target.classList.contains('pm-view-job')) return;

    e.preventDefault();
    let payload = e.target.getAttribute('data-payload');
    if(!payload) return;

    let data = JSON.parse(payload);

    let html = '';
    Object.keys(data).forEach(label => {
        html += `<p><strong>${label}</strong> ${data[label] ?? ''}</p>`;
    });

    document.getElementById('pm-job-fields').innerHTML = html;
    document.getElementById('pm-job-modal').style.display = 'block';
});

document.addEventListener('click', function(e){
    if(e.target.classList.contains('pm-close') || e.target.id === 'pm-job-modal') {
        document.getElementById('pm-job-modal').style.display = 'none';
    }
});

/** OPEN CREDIT MODAL */
document.addEventListener('click', function(e){
    if (e.target.classList.contains('pm-open-credit-modal')) {
        document.getElementById('pm-credit-modal').style.display = 'block';
    }
});

/** BUY SELECTED CREDIT PACK */
document.addEventListener('click', function(e){
    if (e.target.classList.contains('pm-buy-credit')) {
        let qty = e.target.getAttribute('data-qty');
        if (!qty) return;

        window.location = "<?php echo home_url('/'); ?>" + "?pm_buy_credits=" + qty;
    }
});

</script>

<!-- CREDIT MODAL -->
<div id="pm-credit-modal" class="pm-modal">
    <div class="pm-modal-content">
        <span class="pm-close">&times;</span>
        <h3>Purchase Credits</h3>

        <p>Select a credit bundle</p>

        <p>
            <a class="button pm-buy-credit" data-qty="1">
                Buy 1 credit (£<?php echo $credit_prices['price_1']; ?>)
            </a>
        </p>

        <p>
            <a class="button pm-buy-credit" data-qty="5">
                Buy 5 credits (£<?php echo $credit_prices['price_5']; ?>)
            </a>
        </p>

        <p>
            <a class="button pm-buy-credit" data-qty="10">
                Buy 10 credits (£<?php echo $credit_prices['price_10']; ?>)
            </a>
        </p>

    </div>
</div>


<?php
    return ob_get_clean();
});
