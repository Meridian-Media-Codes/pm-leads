<?php
if (!defined('ABSPATH')) exit;

/**
 * ===============================
 * PM Leads – Credits and Purchases
 * ===============================
 *
 * Expects:
 * - Vendor role: pm_vendor
 * - Job CPT: pm_job
 * - Job meta:
 *     - purchase_count (int)
 *     - pm_purchased_by (array of user IDs)    // who bought
 *     - _pm_wc_product_id (int)                // linked Woo product for “Buy now”
 *     - customer_* and address fields for email (same as your current usage)
 * - Product meta:
 *     - _pm_lead_credit_value (int)            // credit top-up amount for this product
 *     - _pm_job_id (int)                       // if this product represents a specific job purchase
 *
 * You can change defaults via pm_leads_get_options() if you have it.
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
        return [
            'purchase_limit' => $limit,
        ];
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

/** Increment purchase count and return new value */
if (!function_exists('pm_leads_inc_purchase_count')) {
    function pm_leads_inc_purchase_count($job_id) {
        $c = pm_leads_get_purchase_count($job_id) + 1;
        update_post_meta($job_id, 'purchase_count', $c);
        return $c;
    }
}

/** Array of vendor IDs who bought this job */
if (!function_exists('pm_leads_get_purchased_vendors')) {
    function pm_leads_get_purchased_vendors($job_id) {
        $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($buyers)) $buyers = [];
        // Back-compat with any older key you may have used
        if (!$buyers) {
            $old = get_post_meta($job_id, 'purchased_vendors', true);
            if (is_array($old) && $old) $buyers = $old;
        }
        return $buyers;
    }
}

/** Check if vendor already bought this job */
if (!function_exists('pm_leads_vendor_has_bought')) {
    function pm_leads_vendor_has_bought($job_id, $vendor_id) {
        return in_array((int)$vendor_id, pm_leads_get_purchased_vendors($job_id), true);
    }
}

/** Mark vendor as purchaser of the job */
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

/** Email vendor confirmation with job details */
if (!function_exists('pm_leads_email_vendor_receipt')) {
    function pm_leads_email_vendor_receipt($vendor_id, $job_id, $method_label = 'Credits') {
        $user = get_user_by('id', $vendor_id);
        if (!$user) return;

        $fields = [
            'customer_name','customer_email','customer_phone','customer_message',
            'current_postcode','current_address','bedrooms_current',
            'new_postcode','new_address','bedrooms_new'
        ];
        $meta = [];
        foreach ($fields as $f) {
            $meta[$f] = get_post_meta($job_id, $f, true);
        }

        $subject = sprintf('[Lead #%d] Purchased via %s', $job_id, $method_label);

        $lines = [];
        $lines[] = 'You have purchased a new moving lead.';
        $lines[] = '';
        $lines[] = 'Lead details:';
        $lines[] = 'From: ' . ($meta['current_postcode'] ?: '');
        $lines[] = 'To:   ' . ($meta['new_postcode'] ?: '');
        $lines[] = '';
        $lines[] = 'Customer name: ' . ($meta['customer_name'] ?: '');
        $lines[] = 'Customer email: ' . ($meta['customer_email'] ?: '');
        $lines[] = 'Customer phone: ' . ($meta['customer_phone'] ?: '');
        $lines[] = '';
        $lines[] = 'Current address: ' . ($meta['current_address'] ?: '');
        $lines[] = 'New address:     ' . ($meta['new_address'] ?: '');
        $lines[] = '';
        $lines[] = 'Bedrooms (current): ' . ($meta['bedrooms_current'] ?: '');
        $lines[] = 'Bedrooms (new):     ' . ($meta['bedrooms_new'] ?: '');
        $lines[] = '';
        $lines[] = 'Message:';
        $lines[] = ($meta['customer_message'] ?: '-');
        $lines[] = '';
        $lines[] = 'Lead ID: #' . $job_id;
        $lines[] = 'Purchased with: ' . $method_label;

        wp_mail($user->user_email, $subject, implode("\n", $lines));
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
 * Woo order completed → grant credits and
 * unlock cash-path leads (linked products)
 * ========================================== */

add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return;

    $to_add = 0;

    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        // Top-up credits
        $credits = (int) get_post_meta($pid, '_pm_lead_credit_value', true);
        if ($credits > 0) {
            $qty = max(1, (int) $item->get_quantity());
            $to_add += ($credits * $qty);
        }
    }

    if ($to_add > 0) {
        $bal = (int) get_user_meta($user_id, 'pm_credit_balance', true);
        update_user_meta($user_id, 'pm_credit_balance', $bal + $to_add);
    }

    // Cash path: product directly linked to a job
    $opts = pm_leads_opts();
    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        $job_id = (int) get_post_meta($pid, '_pm_job_id', true);
        if (!$job_id) continue;

        // Respect 1 per vendor and global limit
        if (pm_leads_vendor_has_bought($job_id, $user_id)) {
            continue;
        }
        $count = pm_leads_get_purchase_count($job_id);
        if ($count >= $opts['purchase_limit']) {
            continue;
        }

        pm_leads_mark_vendor_bought($job_id, $user_id);
        $new_count = pm_leads_inc_purchase_count($job_id);

        // Optional: mark sold_out taxonomy when limit reached
        if ($new_count >= $opts['purchase_limit']) {
            $term = term_exists('sold_out', 'pm_job_status');
            if (!$term) $term = wp_insert_term('sold_out', 'pm_job_status');
            if (!is_wp_error($term) && isset($term['term_id'])) {
                wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
            }
        }

        // Email vendor
        pm_leads_email_vendor_receipt($user_id, $job_id, 'Woo checkout');
    }
});

/* ==========================================
 * Buy with credits endpoint
 * URL: /wp-admin/admin-post.php?action=pm_buy_lead_credits&job_id=123&_wpnonce=...
 * ========================================== */

add_action('admin_post_pm_buy_lead_credits', 'pm_leads_handle_buy_with_credits');
function pm_leads_handle_buy_with_credits() {
    $redirect = admin_url('admin.php?page=pm-leads-jobs');

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

    // Already bought by this vendor
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

    // Need 1 credit
    $bal = (int) get_user_meta($vendor_id, 'pm_credit_balance', true);
    if ($bal < 1) {
        wp_safe_redirect(add_query_arg('pm_msg', 'no_credits', $redirect));
        exit;
    }

    // Deduct one credit
    update_user_meta($vendor_id, 'pm_credit_balance', max(0, $bal - 1));

    // Record purchase
    pm_leads_mark_vendor_bought($job_id, $vendor_id);
    $new_count = pm_leads_inc_purchase_count($job_id);

    // Sync Woo stock if product linked
    $product_id = pm_leads_get_job_product_id($job_id);
    if ($product_id) {
        pm_leads_reduce_stock($product_id);
    }

    // Email vendor
    pm_leads_email_vendor_receipt($vendor_id, $job_id, 'Credits');

    // Sold out taxonomy if reached limit
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
