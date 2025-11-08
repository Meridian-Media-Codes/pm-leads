<?php
if (!defined('ABSPATH')) exit;

/**
 * Create a hidden WooCommerce product linked to a pm_job post
 *
 * - price = options['price_per_lead']
 * - credits = 1
 * - stock = 5
 * - type: simple, virtual
 * - visible: no
 */

add_action('pm_leads_job_created', 'pm_leads_create_wc_product_for_job', 10, 1);

function pm_leads_create_wc_product_for_job($job_id) {

    if (!function_exists('wc_get_product')) {
        return; // WooCommerce inactive
    }

    // Already exists?
    $existing = get_post_meta($job_id, '_pm_wc_product_id', true);
    if ($existing) {
        return;
    }

    // Load price setting
    $opts  = function_exists('pm_leads_get_options') ? pm_leads_get_options() : [];
    $price = isset($opts['price_per_lead']) ? floatval($opts['price_per_lead']) : 0;

    // Create WC product post
    $product_data = [
        'post_title'   => 'Lead #' . $job_id,
        'post_status'  => 'private',       // hidden from catalog
        'post_type'    => 'product',
        'post_author'  => get_current_user_id(),
    ];

    $product_id = wp_insert_post($product_data);

    if (is_wp_error($product_id) || !$product_id) {
        return;
    }

    /* -------------------------
     * WC Product Meta
     * ------------------------- */

    // Simple product
    update_post_meta($product_id, '_manage_stock', 'yes');
    update_post_meta($product_id, '_stock', 5);          // ✅ only 5 available
    update_post_meta($product_id, '_stock_status', 'instock');
    update_post_meta($product_id, '_sold_individually', 'yes');

    // Virtual
    update_post_meta($product_id, '_virtual', 'yes');

    // Price
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_price', $price);

    // Credits granted when bought
    update_post_meta($product_id, '_pm_lead_credit_value', 1);

    // SKU
    update_post_meta($product_id, '_sku', 'job-' . $job_id);

    // Hide from catalog
    update_post_meta($product_id, '_visibility', 'hidden');
    update_post_meta($product_id, '_catalog_visibility', 'hidden');

    // Link product → job
    update_post_meta($product_id, '_pm_job_id', $job_id);

    // Link job → product
    update_post_meta($job_id, '_pm_wc_product_id', $product_id);

    // Ensure purchase_count is initialised
    if (get_post_meta($job_id, 'purchase_count', true) === '') {
        update_post_meta($job_id, 'purchase_count', 0);
    }
}


/**
 * Optional — keep stock synced with purchase_count
 * If someone buys via Woo “Buy now”
 * (but credits.php also updates the DB)
 */
add_action('woocommerce_order_status_completed', function($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item) {

        $pid = $item->get_product_id();
        $job_id = intval(get_post_meta($pid, '_pm_job_id', true));
        if (!$job_id) {
            continue;
        }

        // Count how many have bought
        $count = intval(get_post_meta($job_id, 'purchase_count', true));
        $limit = 5;  // job max

        $new_stock = max(0, $limit - $count);
        update_post_meta($pid, '_stock', $new_stock);

        if ($new_stock === 0) {
            update_post_meta($pid, '_stock_status', 'outofstock');
        }
    }
});
