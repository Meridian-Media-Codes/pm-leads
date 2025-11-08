<?php
if (!defined('ABSPATH')) exit;

/**
 * ===============================
 * PM Leads – Credits and Purchases
 * ===============================
 */

/* =========================
 * Utilities and helpers
 * ========================= */

/** Current vendor user ID or 0 */
if (!function_exists('pm_leads_current_vendor_id')) {
    function pm_leads_current_vendor_id() {
        $uid = get_current_user_id();
        if (!$uid) return 0;
        $u = get_user_by('id', $uid);
        if (!$u || !in_array('pm_vendor', (array)$u->roles, true)) return 0;
        return (int) $uid;
    }
}

/** Options helper with sensible defaults */
if (!function_exists('pm_leads_opts')) {
    function pm_leads_opts() {
        $limit = 5;
        if (function_exists('pm_leads_get_options')) {
            $o = pm_leads_get_options();
            if (isset($o['purchase_limit'])) {
                $limit = max(1, (int)$o['purchase_limit']);
            }
        }
        return ['purchase_limit' => $limit];
    }
}

/** Get Woo product ID linked to a job */
if (!function_exists('pm_leads_get_job_product_id')) {
    function pm_leads_get_job_product_id($job_id) {
        return absint(get_post_meta($job_id, '_pm_wc_product_id', true));
    }
}

/** Purchase count for a job */
if (!function_exists('pm_leads_get_purchase_count')) {
    function pm_leads_get_purchase_count($job_id) {
        $v = get_post_meta($job_id, 'purchase_count', true);
        return ($v === '' ? 0 : (int)$v);
    }
}

/** Increment purchase count */
if (!function_exists('pm_leads_inc_purchase_count')) {
    function pm_leads_inc_purchase_count($job_id) {
        $c = pm_leads_get_purchase_count($job_id) + 1;
        update_post_meta($job_id, 'purchase_count', $c);
        return $c;
    }
}

/** Get vendors who bought job */
if (!function_exists('pm_leads_get_purchased_vendors')) {
    function pm_leads_get_purchased_vendors($job_id) {
        $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($buyers)) $buyers = [];

        // Back compat
        if (!$buyers) {
            $old = get_post_meta($job_id, 'purchased_vendors', true);
            if (is_array($old) && $old) $buyers = $old;
        }
        return $buyers;
    }
}

/** Check vendor already bought */
if (!function_exists('pm_leads_vendor_has_bought')) {
    function pm_leads_vendor_has_bought($job_id, $vendor_id) {
        return in_array((int)$vendor_id, pm_leads_get_purchased_vendors($job_id), true);
    }
}

/** Mark vendor as buyer */
if (!function_exists('pm_leads_mark_vendor_bought')) {
    function pm_leads_mark_vendor_bought($job_id, $vendor_id) {
        $buyers = pm_leads_get_purchased_vendors($job_id);
        $vendor_id = (int)$vendor_id;
        if (!in_array($vendor_id, $buyers, true)) {
            $buyers[] = $vendor_id;
            update_post_meta($job_id, 'pm_purchased_by', $buyers);
        }
    }
}

/** Reduce Woo stock by 1 if managed */
if (!function_exists('pm_leads_reduce_stock')) {
    function pm_leads_reduce_stock($product_id) {
        if (!$product_id || !class_exists('WC_Product')) return;
        $product = wc_get_product($product_id);
        if (!$product) return;
        if ($product->managing_stock()) {
            wc_update_product_stock($product, -1, 'decrease');
        }
    }
}


/* ==========================================
 * Product meta box: _pm_lead_credit_value
 * ========================================== */

add_action('add_meta_boxes', function () {
    add_meta_box('pm_lead_credits', 'PM Lead Credits', 'pm_lead_credits_box', 'product', 'side', 'default');
});

function pm_lead_credits_box($post) {
    $val = get_post_meta($post->ID, '_pm_lead_credit_value', true);
    echo '<p><label>Credits granted on completion</label><br/>';
    echo '<input type="number" min="0" name="pm_lead_credit_value" value="' . esc_attr($val === '' ? 0 : intval($val)) . '" class="small-text" /></p>';
    wp_nonce_field('pm_lead_credit_meta', 'pm_lead_credit_nonce');
}

add_action('save_post_product', function ($post_id) {
    if (!isset($_POST['pm_lead_credit_nonce']) || !wp_verify_nonce($_POST['pm_lead_credit_nonce'], 'pm_lead_credit_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $val = isset($_POST['pm_lead_credit_value']) ? max(0, intval($_POST['pm_lead_credit_value'])) : 0;
    update_post_meta($post_id, '_pm_lead_credit_value', $val);
});


/* ==========================================
 * Woo order completed → grant credits + job unlock
 * ========================================== */

add_action('woocommerce_order_status_completed', function ($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return;

    $opts = pm_leads_opts();

    /* Grant credit-topups */
    $to_add = 0;
    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        $credits = (int)get_post_meta($pid, '_pm_lead_credit_value', true);
        if ($credits > 0) {
            $qty = max(1, (int)$item->get_quantity());
            $to_add += ($credits * $qty);
        }
    }
    if ($to_add > 0) {
        $bal = (int)get_user_meta($user_id, 'pm_credit_balance', true);
        update_user_meta($user_id, 'pm_credit_balance', $bal + $to_add);

        // optional: warn if still low
        pm_leads_maybe_warn_low_credits($user_id);
    }

    /* Cash path purchase of job products */
    foreach ($order->get_items() as $item) {

        $pid = $item->get_product_id();
        $job_id = (int)get_post_meta($pid, '_pm_job_id', true);
        if (!$job_id) continue;

        if (pm_leads_vendor_has_bought($job_id, $user_id)) {
            continue;
        }

        $count = pm_leads_get_purchase_count($job_id);
        if ($count >= $opts['purchase_limit']) {
            continue;
        }

        // Mark purchased
        pm_leads_mark_vendor_bought($job_id, $user_id);
        $new_count = pm_leads_inc_purchase_count($job_id);

        // Stock sync
        pm_leads_reduce_stock($pid);

        // Fire unified purchase event for email
        do_action('pm_lead_purchased_with_credits', $job_id, $user_id);

        // Sold out taxonomy
        if ($new_count >= $opts['purchase_limit']) {
            $term = term_exists('sold_out', 'pm_job_status');
            if (!$term) $term = wp_insert_term('sold_out', 'pm_job_status');
            if (!is_wp_error($term) && isset($term['term_id'])) {
                wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
            }
        }
    }
});


/* ==========================================
 * Buy with credits
 * ========================================== */

add_action('admin_post_pm_buy_lead_credits', 'pm_leads_handle_buy_with_credits');

function pm_leads_handle_buy_with_credits() {

    $redirect = admin_url('admin.php?page=pm-leads');

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pm_buy_lead_credits')) {
        wp_safe_redirect(add_query_arg('pm_msg', 'bad_nonce', $redirect));
        exit;
    }

    $vendor_id = pm_leads_current_vendor_id();
    if (!$vendor_id) {
        wp_safe_redirect(add_query_arg('pm_msg', 'not_vendor', $redirect));
        exit;
    }

    $job_id = absint($_GET['job_id'] ?? 0);
    if (!$job_id || get_post_type($job_id) !== 'pm_job') {
        wp_safe_redirect(add_query_arg('pm_msg', 'bad_job', $redirect));
        exit;
    }

    if (pm_leads_vendor_has_bought($job_id, $vendor_id)) {
        wp_safe_redirect(add_query_arg('pm_msg', 'already_bought', $redirect));
        exit;
    }

    $opts  = pm_leads_opts();
    $count = pm_leads_get_purchase_count($job_id);
    if ($count >= $opts['purchase_limit']) {
        wp_safe_redirect(add_query_arg('pm_msg', 'sold_out', $redirect));
        exit;
    }

    $bal = (int)get_user_meta($vendor_id, 'pm_credit_balance', true);
    if ($bal < 1) {
        wp_safe_redirect(add_query_arg('pm_msg', 'no_credits', $redirect));
        exit;
    }

    /* Deduct credit */
    update_user_meta($vendor_id, 'pm_credit_balance', max(0, $bal - 1));

    /* Mark purchase */
    pm_leads_mark_vendor_bought($job_id, $vendor_id);
    $new_count = pm_leads_inc_purchase_count($job_id);

    /* Stock sync if Woo product exists */
    $product_id = pm_leads_get_job_product_id($job_id);
    if ($product_id) {
        pm_leads_reduce_stock($product_id);
    }

    /* Fire unified hook → template emails */
    do_action('pm_lead_purchased_with_credits', $job_id, $vendor_id);

    /* Maybe warn low credits */
    pm_leads_maybe_warn_low_credits($vendor_id);

    /* Sold out taxonomy if reached limit */
    if ($new_count >= $opts['purchase_limit']) {
        $term = term_exists('sold_out', 'pm_job_status');
        if (!$term) $term = wp_insert_term('sold_out', 'pm_job_status');
        if (!is_wp_error($term) && isset($term['term_id'])) {
            wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
        }
        wp_safe_redirect(add_query_arg('pm_msg', 'purchased_sold_out', $redirect));
        exit;
    }

    wp_safe_redirect(add_query_arg('pm_msg', 'purchased', $redirect));
    exit;
}
