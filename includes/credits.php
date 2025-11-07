<?php
if (!defined('ABSPATH')) exit;

/**
 * Product meta: how many credits this product grants when order completes
 * Key: _pm_lead_credit_value (int)
 */

// Meta box
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

/**
 * Grant credits when WC order completes
 */
add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return;

    $to_add = 0;

    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        $credits = intval(get_post_meta($pid, '_pm_lead_credit_value', true));
        if ($credits > 0) {
            $qty = max(1, intval($item->get_quantity()));
            $to_add += ($credits * $qty);
        }
    }

    if ($to_add > 0) {
        $bal = intval(get_user_meta($user_id, 'pm_credit_balance', true));
        update_user_meta($user_id, 'pm_credit_balance', $bal + $to_add);
    }

    // Also unlock any purchased lead products (cash path)
    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        $job_id = intval(get_post_meta($pid, '_pm_job_id', true));
        if ($job_id) {
            pm_mark_job_purchased_by($job_id, $user_id);
        }
    }
});

/**
 * Helper: mark a job purchased by this vendor and bump counters.
 */
function pm_mark_job_purchased_by($job_id, $user_id) {
    // Purchase limit
    $opts = pm_leads_get_options();
    $limit = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;

    // Already purchased?
    $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
    if (!is_array($buyers)) $buyers = [];
    if (in_array($user_id, $buyers, true)) return;

    $buyers[] = $user_id;
    update_post_meta($job_id, 'pm_purchased_by', $buyers);

    $count = intval(get_post_meta($job_id, 'purchase_count', true));
    $count++;
    update_post_meta($job_id, 'purchase_count', $count);

    // Sold out term
    if ($count >= $limit) {
        $term = term_exists('sold_out', 'pm_job_status');
        if (!$term) $term = wp_insert_term('sold_out', 'pm_job_status');
        if (!is_wp_error($term) && isset($term['term_id'])) {
            wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
        }
    }
}
