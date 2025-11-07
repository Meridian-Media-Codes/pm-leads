<?php
if (!defined('ABSPATH')) exit;

/**
 * Create a hidden WooCommerce product linked to a pm_job post
 */

add_action('pm_leads_job_created', 'pm_leads_create_wc_product_for_job', 10, 1);

function pm_leads_create_wc_product_for_job($job_id) {

    if (!function_exists('wc_get_product')) {
        // WooCommerce inactive
        return;
    }

    // If product already exists, exit.
    $existing = get_post_meta($job_id, '_pm_lead_product_id', true);
    if ($existing) {
        return;
    }

    // Get price from settings
    $opts  = pm_leads_get_options();
    $price = isset($opts['price_per_lead']) ? floatval($opts['price_per_lead']) : 0;

    // Create product
    $product_data = array(
        'post_title'   => 'Lead #' . $job_id,
        'post_status'  => 'private',   // hidden in catalog
        'post_type'    => 'product',
        'post_author'  => get_current_user_id(),
    );

    $product_id = wp_insert_post($product_data);

    if (is_wp_error($product_id) || !$product_id) return;

    // Set WooCommerce meta
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_sku', 'job-' . $job_id);

    // Hide visibility
    update_post_meta($product_id, '_visibility', 'hidden');

    // Link to job
    update_post_meta($job_id, '_pm_lead_product_id', $product_id);
    update_post_meta($product_id, '_pm_job_id', $job_id);
}