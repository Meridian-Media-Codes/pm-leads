<?php


add_action('fluentform_submission_inserted', function ($entryId, $formData) {

    $target_form = 3; // ID of your Request Quote form
    if (intval($formData['form_id']) !== $target_form) {
        return;
    }

    // Map fields
    $current  = sanitize_text_field($formData['current_postcode'] ?? '');
    $new      = sanitize_text_field($formData['new_postcode'] ?? '');
    $beds_n   = absint($formData['bedrooms_new'] ?? 0);
    $beds_c   = absint($formData['bedrooms_current'] ?? 0);
    $email    = sanitize_email($formData['customer_email'] ?? '');
    $msg      = sanitize_textarea_field($formData['customer_message'] ?? '');
    $name     = sanitize_text_field($formData['customer_name'] ?? '');
    $addr_c   = sanitize_text_field($formData['current_address'] ?? '');
    $addr_n   = sanitize_text_field($formData['new_address'] ?? '');

    if (!$current || !$new || !$email) {
        return;
    }

    $title = sprintf('Move %s to %s', $current, $new);
    $job_id = wp_insert_post([
        'post_type'   => 'pm_job',
        'post_title'  => $title,
        'post_status' => 'publish',
    ]);

    if (!$job_id || is_wp_error($job_id)) {
        return;
    }

    update_post_meta($job_id, 'current_postcode', $current);
    pm_job_geocode($job_id, $current);

    update_post_meta($job_id, 'new_postcode', $new);
    update_post_meta($job_id, 'bedrooms_new', $beds_n);
    update_post_meta($job_id, 'bedrooms_current', $beds_c);

    update_post_meta($job_id, 'customer_name', $name);
    update_post_meta($job_id, 'customer_email', $email);
    update_post_meta($job_id, 'customer_message', $msg);

    update_post_meta($job_id, 'current_address', $addr_c);
    update_post_meta($job_id, 'new_address', $addr_n);

    update_post_meta($job_id, 'purchase_count', 0);

    do_action('pm_leads_job_created', $job_id);

}, 10, 2);
