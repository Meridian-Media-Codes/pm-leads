<?php

if (!defined('ABSPATH')) exit;

add_action('fluentform_submission_inserted', function ($entryId, $formData) {

    error_log('PM FF: handler running');
    error_log(print_r($formData, true));

    // BASIC VALIDATION
    if (empty($formData['current_postcode']) || empty($formData['new_postcode'])) {
        error_log('PM FF: Missing postcode fields – skipping');
        return;
    }

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
        error_log("PM FF: Required fields missing");
        return;
    }

    // CREATE JOB
    $title = sprintf('Move %s → %s', $current, $new);
    $job_id = wp_insert_post([
        'post_type'   => 'pm_job',
        'post_title'  => $title,
        'post_status' => 'publish',
    ]);

    if (!$job_id || is_wp_error($job_id)) {
        error_log("PM FF: Job creation failed");
        return;
    }

    error_log("PM FF: Job created ID={$job_id}");

    // SAVE META
    update_post_meta($job_id, 'current_postcode', $current);
    update_post_meta($job_id, 'new_postcode',     $new);

    update_post_meta($job_id, 'bedrooms_new',     $beds_n);
    update_post_meta($job_id, 'bedrooms_current', $beds_c);

    update_post_meta($job_id, 'customer_name',    $name);
    update_post_meta($job_id, 'customer_email',   $email);
    update_post_meta($job_id, 'customer_message', $msg);

    update_post_meta($job_id, 'current_address',  $addr_c);
    update_post_meta($job_id, 'new_address',      $addr_n);

    update_post_meta($job_id, 'purchase_count',   0);

    // GEO
    error_log("PM FF: geocode FROM");
    pm_job_geocode($job_id, $current, 'pm_job_from');

    error_log("PM FF: geocode TO");
    pm_job_geocode($job_id, $new, 'pm_job_to');

    // ✅ VITAL: FIRE NOTIFICATION HOOK
    error_log("PM FF: firing pm_lead_created for job {$job_id}");
    do_action('pm_lead_created', $job_id);

    // Also fire secondary hook if needed
    do_action('pm_leads_job_created', $job_id);

}, 10, 2);
