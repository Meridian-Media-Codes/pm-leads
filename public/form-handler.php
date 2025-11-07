<?php
if ( ! defined('ABSPATH') ) exit;

add_action('init', function () {

    if (! isset($_POST['pm_leads_submit'])) return;
    if (! isset($_POST['pm_leads_nonce']) || ! wp_verify_nonce($_POST['pm_leads_nonce'], 'pm_leads_submit')) return;

    // basic fields
    $current = sanitize_text_field($_POST['current_postcode'] ?? '');
    $new     = sanitize_text_field($_POST['new_postcode'] ?? '');

    $beds_n  = absint($_POST['bedrooms_new'] ?? 0);
    $beds_c  = absint($_POST['bedrooms_current'] ?? 0);

    $email   = sanitize_email($_POST['customer_email'] ?? '');
    $msg     = sanitize_textarea_field($_POST['customer_message'] ?? '');

    $name          = sanitize_text_field($_POST['customer_name'] ?? '');
    $addr_current  = sanitize_text_field($_POST['current_address'] ?? '');
    $addr_new      = sanitize_text_field($_POST['new_address'] ?? '');

    if (!$current || !$new || !$email) {
        wp_die(__('Missing required fields', 'pm-leads'));
    }

    // create post
    $title = sprintf('Move %s → %s', $current, $new);
    $job_id = wp_insert_post([
        'post_type'   => 'pm_job',
        'post_title'  => $title,
        'post_status' => 'publish',
    ]);

    if (is_wp_error($job_id) || ! $job_id) {
        wp_die(__('Could not create job', 'pm-leads'));
    }

    // save raw info
    update_post_meta($job_id, 'current_postcode', $current);
    update_post_meta($job_id, 'new_postcode',     $new);

    update_post_meta($job_id, 'bedrooms_new',     $beds_n);
    update_post_meta($job_id, 'bedrooms_current', $beds_c);

    update_post_meta($job_id, 'customer_name',    $name);
    update_post_meta($job_id, 'customer_email',   $email);
    update_post_meta($job_id, 'customer_message', $msg);

    update_post_meta($job_id, 'current_address',  $addr_current);
    update_post_meta($job_id, 'new_address',      $addr_new);

    update_post_meta($job_id, 'purchase_count',   0);

    /**
     * ✅ GEO (both origin + destination)
     */
    pm_job_geocode($job_id, $current);
    pm_job_geocode($job_id, $new);

    do_action('pm_leads_job_created', $job_id);

    // assign status "available"
    $term = term_exists('available', 'pm_job_status');
    if (! $term) {
        $term = wp_insert_term('available', 'pm_job_status');
    }
    if (! is_wp_error($term) && isset($term['term_id'])) {
        wp_set_post_terms($job_id, [$term['term_id']], 'pm_job_status', false);
    }

    // redirect
    $redirect = home_url('/thank-you/');
    wp_safe_redirect($redirect);
    exit;
});
