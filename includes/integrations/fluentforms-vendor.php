<?php
if (!defined('ABSPATH')) exit;

add_action('fluentform_submission_inserted', function ($entryId, $formData) {

    // Check if this looks like a vendor form
    if (empty($formData['email']) || empty($formData['company_name'])) {
        return;
    }

    $email    = sanitize_email($formData['email']);
    $company  = sanitize_text_field($formData['company_name']);
    $fname    = sanitize_text_field($formData['first_name'] ?? '');
    $lname    = sanitize_text_field($formData['last_name'] ?? '');
    $phone    = sanitize_text_field($formData['phone'] ?? '');
    $postcode = sanitize_text_field($formData['postcode'] ?? '');
    $radius   = absint($formData['radius'] ?? 0);
    $years    = sanitize_text_field($formData['years_in_business'] ?? '');

    // insurance docs (FF stores URLs)
    $insurance = '';
    if (!empty($formData['insurance_docs'])) {
        $insurance = is_array($formData['insurance_docs'])
            ? reset($formData['insurance_docs'])
            : $formData['insurance_docs'];
    }

    // If email exists, stop. (for now)
    if (email_exists($email)) {
        error_log("PM-VENDOR: user exists: $email");
        return;
    }

    // Create pending user
    $user_id = wp_insert_user([
        'user_login'   => $email,
        'user_email'   => $email,
        'display_name' => $company,
        'role'         => 'pm_vendor'
    ]);

    if (is_wp_error($user_id)) {
        error_log('PM-VENDOR: failed to create user');
        return;
    }

    update_user_meta($user_id, 'company_name',        $company);
    update_user_meta($user_id, 'first_name',          $fname);
    update_user_meta($user_id, 'last_name',           $lname);
    update_user_meta($user_id, 'phone',               $phone);
    update_user_meta($user_id, 'postcode',            $postcode);
    update_user_meta($user_id, 'radius',              $radius);
    update_user_meta($user_id, 'years_in_business',   $years);
    update_user_meta($user_id, 'insurance_docs',      $insurance);

    // credits start empty
    update_user_meta($user_id, 'pm_credit_balance', 0);

    // geocode
    if (function_exists('pm_leads_geocode')) {
        $coords = pm_leads_geocode($postcode);
        if ($coords && !empty($coords['lat']) && !empty($coords['lng'])) {
            update_user_meta($user_id, 'pm_latitude',  $coords['lat']);
            update_user_meta($user_id, 'pm_longitude', $coords['lng']);
        }
    }

    do_action('pm_vendor_created', $user_id);

}, 10, 2);
