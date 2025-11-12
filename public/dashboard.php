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
    ?>

    <div class="pm-vdash">

        <?php if ($msg && isset($notices[$msg])): ?>
            <div class="pm-alert"><?php echo esc_html($notices[$msg]); ?></div>
        <?php endif; ?>

        <!-- Top summary -->
        <div class="pm-grid pm-grid--top">
            <section class="pm-card pm-card--summary">
                <header class="pm-card__header">
                    <h3 class="pm-card__title">Vendor dashboard</h3>
                    <div class="pm-chip">Credits: <strong><?php echo intval($balance); ?></strong></div>
                </header>

                <div class="pm-card__body">
                    <p class="pm-muted" style="margin:0 0 14px;">
                        From your account dashboard you can view your
                        <a href="<?php echo esc_url(wc_get_endpoint_url('orders','',wc_get_page_permalink('myaccount'))); ?>">recent orders</a>,
                        manage your <a href="<?php echo esc_url(wc_get_endpoint_url('edit-address','',wc_get_page_permalink('myaccount'))); ?>">addresses</a>,
                        and <a href="<?php echo esc_url(wc_get_endpoint_url('edit-account','',wc_get_page_permalink('myaccount'))); ?>">account details</a>.
                    </p>

                    <button class="pm-btn pm-btn--brand pm-open-credit-modal" type="button">
                        Purchase credits
                    </button>
                </div>
            </section>
        </div>

        <!-- Available + Purchased grid -->
        <div class="pm-grid pm-grid--main">

            <!-- Available leads -->
            <section class="pm-card">
                <header class="pm-card__header">
                    <h3 class="pm-card__title">Available leads</h3>
                </header>

                <div class="pm-card__body">
                    <?php if (empty($available)): ?>
                        <p class="pm-empty">No leads available in your area right now.</p>
                    <?php else: ?>
                        <div class="pm-table-wrap">
                            <table class="pm-table">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Dist to origin (mi)</th>
                                    <th>Job distance (mi)</th>
                                    <th>Purchases</th>
                                    <th class="pm-table__actions">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($available as $row):
                                    $job_id  = $row['job_id'];
                                    $product = intval(get_post_meta($job_id, '_pm_lead_product_id', true));
                                    ?>
                                    <tr>
                                        <td>#<?php echo intval($job_id); ?></td>
                                        <td><?php echo esc_html($row['from']); ?></td>
                                        <td><?php echo esc_html($row['to']); ?></td>
                                        <td><?php echo number_format((float)$row['dist_to_origin'], 1); ?></td>
                                        <td><?php echo number_format((float)$row['job_distance'], 1); ?></td>
                                        <td><?php echo intval($row['purchases']); ?>/5</td>
                                        <td class="pm-table__actions">
                                            <a class="pm-btn pm-btn--sm pm-btn--brand"
                                               href="<?php echo esc_url(add_query_arg([
                                                   'pm_buy_lead' => $job_id,
                                                   '_wpnonce'    => wp_create_nonce('pm_buy_lead_' . $job_id),
                                               ])); ?>">
                                                Buy with 1 credit
                                            </a>

                                            <?php if ($product):
                                                $product_url = get_permalink($product); ?>
                                                <a class="pm-btn pm-btn--sm"
                                                   href="<?php echo esc_url($product_url); ?>">
                                                    Buy now
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Purchased leads -->
            <section class="pm-card">
                <header class="pm-card__header">
                    <h3 class="pm-card__title">Purchased leads</h3>
                </header>

                <div class="pm-card__body">
                    <?php if (empty($purchased)): ?>
                        <p class="pm-empty">You haven’t purchased any leads yet.</p>
                    <?php else: ?>
                        <div class="pm-table-wrap">
                            <table class="pm-table">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>From</th>
                                    <th>To</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($purchased as $j):
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
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="#"
                                               class="pm-view-job"
                                               data-payload="<?php echo esc_attr($json); ?>"
                                               data-id="<?php echo intval($job_id); ?>">
                                                #<?php echo intval($job_id); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html($details['From Postcode']); ?></td>
                                        <td><?php echo esc_html($details['To Postcode']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div> <!-- /.pm-grid -->

        <!-- Job details modal -->
        <div id="pm-job-modal" class="pm-modal">
            <div class="pm-modal-content pm-card">
                <button type="button" class="pm-close" aria-label="Close">&times;</button>
                <h3 class="pm-card__title" style="margin-bottom:12px;">Lead details</h3>
                <div id="pm-job-fields" class="pm-job-fields"></div>
            </div>
        </div>

        <!-- Credit modal -->
        <div id="pm-credit-modal" class="pm-modal">
            <div class="pm-modal-content pm-card">
                <button type="button" class="pm-close" aria-label="Close">&times;</button>
                <h3 class="pm-card__title" style="margin-bottom:12px;">Purchase credits</h3>

                <div class="pm-credit-grid">
                    <a class="pm-btn pm-btn--brand pm-btn--block pm-buy-credit" data-qty="1">
                        Buy 1 credit (£<?php echo intval($credit_prices['price_1']); ?>)
                    </a>
                    <a class="pm-btn pm-btn--brand pm-btn--block pm-buy-credit" data-qty="5">
                        Buy 5 credits (£<?php echo intval($credit_prices['price_5']); ?>)
                    </a>
                    <a class="pm-btn pm-btn--brand pm-btn--block pm-buy-credit" data-qty="10">
                        Buy 10 credits (£<?php echo intval($credit_prices['price_10']); ?>)
                    </a>
                </div>
            </div>
        </div>

    </div><!-- /.pm-vdash -->

    <script>
    // View purchased lead details
    document.addEventListener('click', function(e){
        if(!e.target.classList.contains('pm-view-job')) return;
        e.preventDefault();
        const payload = e.target.getAttribute('data-payload');
        if(!payload) return;

        const data = JSON.parse(payload);
        let html = '';
        Object.keys(data).forEach(label => {
            html += `<p class="pm-field"><strong>${label}</strong> <span>${data[label] ?? ''}</span></p>`;
        });

        document.getElementById('pm-job-fields').innerHTML = html;
        document.getElementById('pm-job-modal').style.display = 'block';
        document.body.classList.add('pm-modal-open');
    });

    // Close modals
    document.addEventListener('click', function(e){
        if (e.target.classList.contains('pm-close') ||
            e.target.id === 'pm-job-modal' ||
            e.target.id === 'pm-credit-modal') {
            document.getElementById('pm-job-modal').style.display = 'none';
            document.getElementById('pm-credit-modal').style.display = 'none';
            document.body.classList.remove('pm-modal-open');
        }
    });

    // Open credit modal
    document.addEventListener('click', function(e){
        if (e.target.classList.contains('pm-open-credit-modal')) {
            document.getElementById('pm-credit-modal').style.display = 'block';
            document.body.classList.add('pm-modal-open');
        }
    });

    // Buy selected credit pack
    document.addEventListener('click', function(e){
        if (e.target.classList.contains('pm-buy-credit')) {
            const qty = e.target.getAttribute('data-qty');
            if (!qty) return;
            window.location = "<?php echo home_url('/'); ?>" + "?pm_buy_credits=" + qty;
        }
    });
    </script>

    <?php
    return ob_get_clean();
});

