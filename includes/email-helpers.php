<?php
if (!defined('ABSPATH')) exit;

/**
 * EMAIL HELPERS + TRIGGERS
 * Centralised storage, merge tag replacement, and all send hooks.
 *
 * Email templates are stored in a dedicated option so they can't be
 * overwritten by the General settings sanitizer.
 */
const PM_LEADS_EMAIL_OPT = 'pm_leads_emails';

/* ---------------------------------------------
   Storage helpers
--------------------------------------------- */

/** Get template by key */
function pm_leads_get_email_template($key) {
    $all = get_option(PM_LEADS_EMAIL_OPT, []);
    $tpl = is_array($all) && isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
    return [
        'enabled' => !empty($tpl['enabled']) ? 1 : 0,
        'subject' => $tpl['subject'] ?? '',
        'body'    => $tpl['body']    ?? '',
    ];
}

/** Save template by key (independent option) */
function pm_leads_save_email_template($key, $data) {
    $all = get_option(PM_LEADS_EMAIL_OPT, []);
    if (!is_array($all)) $all = [];

    $all[$key] = [
        'enabled' => !empty($data['enabled']) ? 1 : 0,
        'subject' => sanitize_text_field($data['subject'] ?? ''),
        'body'    => wp_kses_post($data['body'] ?? ''),
    ];

    update_option(PM_LEADS_EMAIL_OPT, $all, false);
}

/* ---------------------------------------------
   Compose + send
--------------------------------------------- */

/** Merge tag replacement */
function pm_leads_apply_merge_tags($text, $data = []) {
    if (!is_array($data)) $data = [];
    foreach ($data as $tag => $value) {
        $text = str_replace('{' . $tag . '}', (string) $value, $text);
    }
    return $text;
}

/** Return HTML mail type for wp_mail */
function pm_leads_mail_html_type() { return 'text/html'; }

/** Send a template email */
function pm_leads_send_template($key, $to, $data = []) {
    $tpl = pm_leads_get_email_template($key);
    if (!$tpl['enabled'] || empty($to)) return false;

    $site = get_bloginfo('name');
    $defaults = [
        'site_name'     => $site,
        'site_url'      => home_url(),
        'dashboard_url' => admin_url('admin.php?page=pm-leads'),
    ];
    $data = array_merge($defaults, $data);

    $subject = pm_leads_apply_merge_tags($tpl['subject'], $data);
    $body    = pm_leads_apply_merge_tags($tpl['body'],    $data);

    if ($subject === '') $subject = $site;
    if ($body === '')    $body    = ' ';

    add_filter('wp_mail_content_type', 'pm_leads_mail_html_type');
    $sent = wp_mail($to, $subject, wpautop($body));
    remove_filter('wp_mail_content_type', 'pm_leads_mail_html_type');

    return $sent;
}

/* ---------------------------------------------
   Data shaping for merge tags
--------------------------------------------- */

function pm_leads_build_job_tags($job_id, $vendor_id = 0) {
    $data = [
        'job_id'            => $job_id,
        'customer_name'     => get_post_meta($job_id, 'customer_name', true),
        'customer_email'    => get_post_meta($job_id, 'customer_email', true),
        'customer_phone'    => get_post_meta($job_id, 'customer_phone', true),
        'customer_message'  => get_post_meta($job_id, 'customer_message', true),

        'current_postcode'  => get_post_meta($job_id, 'current_postcode', true),
        'current_address'   => get_post_meta($job_id, 'current_address', true),
        'bedrooms_current'  => get_post_meta($job_id, 'bedrooms_current', true),

        'new_postcode'      => get_post_meta($job_id, 'new_postcode', true),
        'new_address'       => get_post_meta($job_id, 'new_address', true),
        'bedrooms_new'      => get_post_meta($job_id, 'bedrooms_new', true),

        'purchase_count'    => get_post_meta($job_id, 'purchase_count', true),
    ];

    // Optional geos
    $data['job_from_lat'] = get_post_meta($job_id, 'pm_job_from_lat', true);
    $data['job_from_lng'] = get_post_meta($job_id, 'pm_job_from_lng', true);
    $data['job_to_lat']   = get_post_meta($job_id, 'pm_job_to_lat', true);
    $data['job_to_lng']   = get_post_meta($job_id, 'pm_job_to_lng', true);

    if (!empty($data['job_from_lat']) && !empty($data['job_from_lng']) && !empty($data['job_to_lat']) && !empty($data['job_to_lng']) && function_exists('pm_leads_distance_mi')) {
        $data['job_distance_miles'] = pm_leads_distance_mi($data['job_from_lat'], $data['job_from_lng'], $data['job_to_lat'], $data['job_to_lng']);
    }

    if ($vendor_id) {
        $u = get_user_by('id', $vendor_id);
        if ($u) {
            $data['vendor_name']           = $u->display_name;
            $data['vendor_email']          = $u->user_email;
            $data['vendor_company']        = get_user_meta($vendor_id, 'company_name', true);
            $data['vendor_phone']          = get_user_meta($vendor_id, 'contact_number', true);
            $data['vendor_service_radius'] = get_user_meta($vendor_id, 'service_radius', true);
            $data['vendor_credits']        = get_user_meta($vendor_id, 'pm_credit_balance', true);

            $v_lat = get_user_meta($vendor_id, 'pm_vendor_lat', true);
            $v_lng = get_user_meta($vendor_id, 'pm_vendor_lng', true);
            if ($v_lat && $v_lng && !empty($data['job_from_lat']) && !empty($data['job_from_lng']) && function_exists('pm_leads_distance_mi')) {
                $data['distance_vendor_to_origin_miles'] = pm_leads_distance_mi($v_lat, $v_lng, $data['job_from_lat'], $data['job_from_lng']);
            }
        }
    }

    if (function_exists('pm_leads_opts')) {
        $opts = pm_leads_opts();
        $limit = isset($opts['purchase_limit']) ? (int)$opts['purchase_limit'] : 5;
        $purchases = (int) ($data['purchase_count'] ?: 0);
        $data['purchases']      = $purchases;
        $data['purchase_limit'] = $limit;
        $data['purchases_left'] = max(0, $limit - $purchases);
    }

    return $data;
}

/* ---------------------------------------------
   Triggers
--------------------------------------------- */

add_action('pm_lead_purchased_with_credits', function($job_id, $vendor_id) {
    $data = pm_leads_build_job_tags($job_id, $vendor_id);
    pm_leads_send_template('lead_purchased_vendor',   $data['vendor_email'] ?? '',        $data);
    if (!empty($data['customer_email'])) {
        pm_leads_send_template('lead_purchased_customer', $data['customer_email'], $data);
    }
}, 10, 2);

/**
 * DEBUG: Log vendor notification paths on new lead
 */
add_action('pm_lead_created', function ($job_id) {

    error_log("PM DEBUG: pm_lead_created fired for job {$job_id}");

    $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
    $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);

    if (!$from_lat || !$from_lng) {
        error_log("PM DEBUG: Job {$job_id} missing coords — from_lat={$from_lat}, from_lng={$from_lng}");
        return;
    }

    $opts   = function_exists('pm_leads_get_options') ? pm_leads_get_options() : [];
    $radius = isset($opts['default_radius']) ? intval($opts['default_radius']) : 50;
    error_log("PM DEBUG: Radius = {$radius}");

    $vendors = get_users([
        'role'   => 'pm_vendor',
        'fields' => ['ID']
    ]);

    error_log("PM DEBUG: Found " . count($vendors) . " vendors");

    foreach ($vendors as $v) {
        $vid = $v->ID;
        $v_lat = get_user_meta($vid, 'pm_vendor_lat', true);
        $v_lng = get_user_meta($vid, 'pm_vendor_lng', true);

        if (!$v_lat || !$v_lng) {
            error_log("PM DEBUG: Vendor {$vid} missing coords — v_lat={$v_lat}, v_lng={$v_lng}");
            continue;
        }

        if (!function_exists('pm_leads_distance_mi')) {
            error_log("PM DEBUG: pm_leads_distance_mi missing!");
            continue;
        }

        $dist = pm_leads_distance_mi($from_lat, $from_lng, $v_lat, $v_lng);
        error_log("PM DEBUG: Vendor {$vid} distance={$dist}");

        if ($dist <= $radius) {
            error_log("PM DEBUG: Vendor {$vid} IN RANGE → send email");
        } else {
            error_log("PM DEBUG: Vendor {$vid} OUT OF RANGE");
        }
    }
}, 5);



add_action('pm_lead_created', function ($job_id) {
    $data = pm_leads_build_job_tags($job_id, 0);
    if (!empty($data['customer_email'])) {
        pm_leads_send_template('new_lead_customer', $data['customer_email'], $data);
    }
});

add_action('pm_lead_created', function ($job_id) {
    if (!function_exists('pm_leads_get_options')) return;

    $opts   = pm_leads_get_options();
    $radius = isset($opts['default_radius']) ? intval($opts['default_radius']) : 50;

    $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
    $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);
    if (!$from_lat || !$from_lng || !function_exists('pm_leads_distance_mi')) return;

    $vendors = get_users(['role' => 'pm_vendor', 'fields' => 'ID']);
    foreach ($vendors as $vid) {
        $v_lat = get_user_meta($vid, 'pm_vendor_lat', true);
        $v_lng = get_user_meta($vid, 'pm_vendor_lng', true);
        if (!$v_lat || !$v_lng) continue;

        $dist = pm_leads_distance_mi($from_lat, $from_lng, $v_lat, $v_lng);
        if ($dist <= $radius) {

    $data = pm_leads_build_job_tags($job_id, $vid);
    $to   = $data['vendor_email'] ?? '';

    error_log("PM DEBUG: Vendor {$vid} IN RANGE → sending email to {$to}");

    $sent = pm_leads_send_template('new_lead_vendors', $to, $data);

    error_log("PM DEBUG: send_template result=" . var_export($sent,true));
}


    }
});

add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return;

    foreach ($order->get_items() as $item) {
        $pid     = $item->get_product_id();
        $credits = (int) get_post_meta($pid, '_pm_lead_credit_value', true);
        if ($credits <= 0) continue;

        $data = [
            'vendor_email'      => $order->get_billing_email(),
            'vendor_name'       => $order->get_formatted_billing_full_name(),
            'credits_purchased' => $credits * max(1, (int)$item->get_quantity()),
        ];
        pm_leads_send_template('credits_purchased_vendor', $data['vendor_email'], $data);
    }
});

function pm_leads_mark_vendor_approved($vendor_id) {
    $user = get_user_by('id', $vendor_id);
    if (!$user) return;
    $data = ['vendor_name' => $user->display_name, 'vendor_email' => $user->user_email];
    pm_leads_send_template('vendor_approved_vendor', $data['vendor_email'], $data);
}
add_action('pm_leads_vendor_approved', 'pm_leads_mark_vendor_approved', 10, 1);

function pm_leads_maybe_warn_low_credits($vendor_id) {
    $bal = (int) get_user_meta($vendor_id, 'pm_credit_balance', true);
    if ($bal > 2) return;
    $user = get_user_by('id', $vendor_id);
    if (!$user) return;

    $data = [
        'vendor_name'          => $user->display_name,
        'vendor_email'         => $user->user_email,
        'vendor_credits'       => $bal,
        'low_credit_threshold' => 2,
    ];
    pm_leads_send_template('low_credits_vendor', $data['vendor_email'], $data);
}
