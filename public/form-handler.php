<?php
if ( ! defined('ABSPATH') ) exit;

add_action('init', function () {
    if (! isset($_POST['pm_leads_submit'])) return;
    if (! isset($_POST['pm_leads_nonce']) || ! wp_verify_nonce($_POST['pm_leads_nonce'], 'pm_leads_submit')) return;

    $current = isset($_POST['current_postcode']) ? sanitize_text_field($_POST['current_postcode']) : '';
    $new     = isset($_POST['new_postcode']) ? sanitize_text_field($_POST['new_postcode']) : '';
    $beds_n  = isset($_POST['bedrooms_new']) ? absint($_POST['bedrooms_new']) : 0;
    $beds_c  = isset($_POST['bedrooms_current']) ? absint($_POST['bedrooms_current']) : 0;
    $email   = isset($_POST['customer_email']) ? sanitize_email($_POST['customer_email']) : '';
    $msg     = isset($_POST['customer_message']) ? sanitize_textarea_field($_POST['customer_message']) : '';

    if (!$current || !$new || !$beds_n || !$beds_c || !$email) {
        wp_die(__('Missing required fields', 'pm-leads'));
    }

    // Create the job
    $title = sprintf('Move %s to %s', $current, $new);
    $job_id = wp_insert_post([
        'post_type'   => 'pm_job',
        'post_title'  => $title,
        'post_status' => 'publish', // available
    ]);
    if (is_wp_error($job_id) || ! $job_id) {
        wp_die(__('Could not create job', 'pm-leads'));
    }

    update_post_meta($job_id, 'current_postcode', $current);
    update_post_meta($job_id, 'new_postcode', $new);
    update_post_meta($job_id, 'bedrooms_new', $beds_n);
    update_post_meta($job_id, 'bedrooms_current', $beds_c);
    update_post_meta($job_id, 'customer_email', $email);
    update_post_meta($job_id, 'customer_message', $msg);
    update_post_meta($job_id, 'purchase_count', 0);

    do_action('pm_leads_job_created', $job_id);

    // Assign status term "available" if present or create it once
    $term = term_exists('available', 'pm_job_status');
    if (! $term) {
        $term = wp_insert_term('available', 'pm_job_status');
    }
    if (! is_wp_error($term) && isset($term['term_id'])) {
        wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
    }

    // TODO: create hidden WooCommerce product and notify vendors
    // Redirect to a thanks page or back
    $redirect = home_url('/thank-you/');
    wp_safe_redirect($redirect);
    exit;
});
