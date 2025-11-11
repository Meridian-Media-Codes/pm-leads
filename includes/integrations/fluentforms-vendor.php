<?php
if (!defined('ABSPATH')) exit;

add_action('fluentform_submission_inserted', function ($entryId, $formData) {

    // REQUIRED fields
    if (empty($formData['vendor_email']) || empty($formData['company_name'])) {
        return;
    }

    // Map fields using YOUR SLUGS
    $email    = sanitize_email($formData['vendor_email']);
    $company  = sanitize_text_field($formData['company_name']);
    $fname    = sanitize_text_field($formData['first_name'] ?? '');
    $lname    = sanitize_text_field($formData['last_name'] ?? '');
    $phone    = sanitize_text_field($formData['contact_number'] ?? '');
    $postcode = sanitize_text_field($formData['business_postcode'] ?? '');
    $radius   = absint($formData['service_radius'] ?? 0);
    $years    = sanitize_text_field($formData['years_in_business'] ?? '');

    // Insurance docs (url/array)
    $insurance = '';
    if (!empty($formData['insurance_docs'])) {
        $insurance = is_array($formData['insurance_docs'])
            ? reset($formData['insurance_docs'])
            : $formData['insurance_docs'];
    }

    // Avoid duplicate account
    if (email_exists($email)) {
        error_log("PM-VENDOR: email exists ($email)");
        return;
    }

    // Create vendor user
    $user_id = wp_insert_user([
        'user_login'   => $email,
        'user_email'   => $email,
        'display_name' => $company,
        'role'         => 'pm_vendor'
    ]);

    if (is_wp_error($user_id)) {
        error_log("PM-VENDOR: failed to create user for $email");
        return;
    }

    // Store meta
    update_user_meta($user_id, 'company_name',       $company);
    update_user_meta($user_id, 'first_name',         $fname);
    update_user_meta($user_id, 'last_name',          $lname);
    update_user_meta($user_id, 'contact_number',     $phone);
    update_user_meta($user_id, 'business_postcode',  $postcode);
    update_user_meta($user_id, 'service_radius',     $radius);
    update_user_meta($user_id, 'years_in_business',  $years);
    update_user_meta($user_id, 'insurance_docs',     $insurance);

    // Start credit balance at 0
    update_user_meta($user_id, 'pm_credit_balance', 0);

    // GEOCODE business_postcode → lat/lng
    if (function_exists('pm_leads_geocode')) {
        $coords = pm_leads_geocode($postcode);
        if (!empty($coords['lat']) && !empty($coords['lng'])) {
            update_user_meta($user_id, 'pm_vendor_lat', $coords['lat']);
            update_user_meta($user_id, 'pm_vendor_lng', $coords['lng']);
        }
    }

    do_action('pm_vendor_created', $user_id);
    do_action('pm_vendor_application_received', $user_id);


}, 10, 2);
