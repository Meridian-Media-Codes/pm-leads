<?php
if (!defined('ABSPATH')) exit;

/**
 * EMAIL HELPERS + TRIGGERS
 * Merge tags + templating + mail routes
 */
const PM_LEADS_EMAIL_OPT = 'pm_leads_emails';


if (!function_exists('pm_vendor_is_approved')) {
    require_once plugin_dir_path(__FILE__) . 'vendor-status.php';
}

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

/** Save template by key */
function pm_leads_save_email_template($key, $data) {
    $all = get_option(PM_LEADS_EMAIL_OPT, []);
    if (!is_array($all)) $all = [];

    $all[$key] = [
        'enabled' => !empty($data['enabled']) ? 1 : 0,
        'subject' => sanitize_text_field($data['subject'] ?? ''),
        'body' => $data['body'] ?? '',
    ];

    update_option(PM_LEADS_EMAIL_OPT, $all, false);
}

/* ---------------------------------------------
   Compose + send
--------------------------------------------- */

function pm_leads_apply_merge_tags($text, $data = []) {
    if (!is_array($data)) $data = [];

    foreach ($data as $tag => $value) {
        // Allow safe HTML only for specific tags
        if (in_array($tag, ['reset_button', 'login_fallback'], true)) {
            $text = str_replace('{' . $tag . '}', $value, $text); // unescaped HTML
        } else {
            $text = str_replace('{' . $tag . '}', esc_html($value), $text);
        }
    }

    return $text;
}

function pm_leads_mail_html_type() { return 'text/html'; }

function pm_leads_send_template($key, $to, $data = []) {
    $tpl = pm_leads_get_email_template($key);
    if (!$tpl['enabled'] || empty($to)) return false;

    $defaults = [
        'site_name'     => get_bloginfo('name'),
        'site_url'      => home_url(),
        'dashboard_url' => admin_url('admin.php?page=pm-leads')
    ];
    $data = array_merge($defaults, $data);

    $subject = pm_leads_apply_merge_tags($tpl['subject'], $data);
    $body    = pm_leads_apply_merge_tags($tpl['body'],    $data);

    if ($subject === '') $subject = $defaults['site_name'];
    if ($body === '')    $body    = ' ';

    add_filter('wp_mail_content_type', 'pm_leads_mail_html_type');
    $sent = wp_mail($to, $subject, wpautop($body));
    remove_filter('wp_mail_content_type', 'pm_leads_mail_html_type');

    return $sent;
}

/* ---------------------------------------------
   Data shape
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
        'purchase_count'    => get_post_meta($job_id, 'purchase_count', true)
    ];

    $data['job_from_lat'] = get_post_meta($job_id, 'pm_job_from_lat', true);
    $data['job_from_lng'] = get_post_meta($job_id, 'pm_job_from_lng', true);
    $data['job_to_lat']   = get_post_meta($job_id, 'pm_job_to_lat', true);
    $data['job_to_lng']   = get_post_meta($job_id, 'pm_job_to_lng', true);

    if (!empty($data['job_from_lat']) &&
        !empty($data['job_from_lng']) &&
        !empty($data['job_to_lat']) &&
        !empty($data['job_to_lng']) &&
        function_exists('pm_leads_distance_mi')) {

        $data['job_distance_miles'] =
            pm_leads_distance_mi(
                $data['job_from_lat'],
                $data['job_from_lng'],
                $data['job_to_lat'],
                $data['job_to_lng']
            );
    }

    if ($vendor_id) {
        $u = get_user_by('id', $vendor_id);
        if ($u) {
            $data['vendor_name']    = $u->display_name;
            $data['vendor_email']   = $u->user_email;
            $data['vendor_company'] = get_user_meta($vendor_id, 'company_name', true);
            $data['vendor_phone']   = get_user_meta($vendor_id, 'contact_number', true);
            $data['vendor_service_radius'] = get_user_meta($vendor_id, 'service_radius', true);
            $data['vendor_credits'] = get_user_meta($vendor_id, 'pm_credit_balance', true);

            $v_lat = get_user_meta($vendor_id, 'pm_vendor_lat', true);
            $v_lng = get_user_meta($vendor_id, 'pm_vendor_lng', true);

            if ($v_lat && $v_lng && !empty($data['job_from_lat']) &&
                !empty($data['job_from_lng']) && function_exists('pm_leads_distance_mi')) {

                $data['distance_vendor_to_origin_miles'] =
                    pm_leads_distance_mi(
                        $v_lat, $v_lng,
                        $data['job_from_lat'], $data['job_from_lng']
                    );
            }
        }
    }

    if (function_exists('pm_leads_opts')) {
        $opts = pm_leads_opts();
        $l    = isset($opts['purchase_limit']) ? (int) $opts['purchase_limit'] : 5;
        $p    = (int) ($data['purchase_count'] ?: 0);
        $data['purchases']      = $p;
        $data['purchase_limit'] = $l;
        $data['purchases_left'] = max(0, $l - $p);
    }

    return $data;
}

/* ---------------------------------------------
   Triggers
--------------------------------------------- */

add_action('pm_lead_purchased_with_credits', function($job_id, $vendor_id) {
    $data = pm_leads_build_job_tags($job_id, $vendor_id);
    pm_leads_send_template('lead_purchased_vendor',   $data['vendor_email'] ?? '', $data);

    if (!empty($data['customer_email'])) {
        pm_leads_send_template('lead_purchased_customer', $data['customer_email'], $data);
    }
}, 10, 2);

add_action('pm_vendor_application_received', function($vendor_id){

    if (!$vendor_id) return;

    $email   = get_userdata($vendor_id)->user_email;
    if (!$email) return;

    $tmpl = pm_leads_get_email_template('vendor_application_received');

    $subject = $tmpl['subject'] ?? 'Thank you for applying';
    $body    = $tmpl['body'] ?? '';

    // Token replacements
    $body = str_replace(
        ['{{email}}','{{admin_email}}'],
        [$email, get_option('admin_email')],
        $body
    );

    pm_leads_send_email($email, $subject, $body);
});



/**
 * ✅ MAIN vendor notify handler
 */
add_action('pm_lead_created', function ($job_id) {

    error_log("PM DEBUG: pm_lead_created fired for job {$job_id}");

    if (!function_exists('pm_leads_get_options')) {
        error_log("PM DEBUG: pm_leads_get_options missing");
        return;
    }

    $opts   = pm_leads_get_options();
    $radius = isset($opts['default_radius']) ? intval($opts['default_radius']) : 50;

    $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
    $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);

    if (!$from_lat || !$from_lng) {
        error_log("PM DEBUG: Job {$job_id} missing coords");
        return;
    }

    error_log("PM DEBUG: Radius = {$radius}");

    $vendors = get_users([
        'role'   => 'pm_vendor',
        'fields' => ['ID']
    ]);

    error_log("PM DEBUG: Found ".count($vendors)." vendors");

    foreach ($vendors as $v) {

    $vid = $v->ID;

    // ✅ NEW — only email approved vendors
    if (!pm_vendor_is_approved($vid)) {
        error_log("PM DEBUG: Vendor {$vid} is NOT approved — skip");
        continue;
    }

    $v_lat = get_user_meta($vid, 'pm_vendor_lat', true);
    $v_lng = get_user_meta($vid, 'pm_vendor_lng', true);

    if (!$v_lat || !$v_lng) {
        error_log("PM DEBUG: Vendor {$vid} missing coords");
        continue;
    }

    $dist = pm_leads_distance_mi($from_lat, $from_lng, $v_lat, $v_lng);

    if ($dist <= $radius) {

        $data = pm_leads_build_job_tags($job_id, $vid);
        $to   = $data['vendor_email'] ?? '';

        error_log("PM DEBUG: Vendor {$vid} IN RANGE → sending email to {$to}");

        $sent = pm_leads_send_template('new_lead_vendors', $to, $data);

        error_log("PM DEBUG: send_template result=" . var_export($sent,true));
    }
}


}, 10, 1);


/** Customer notification */
add_action('pm_lead_created', function ($job_id) {
    $data = pm_leads_build_job_tags($job_id, 0);
    if (!empty($data['customer_email'])) {
        pm_leads_send_template('new_lead_customer', $data['customer_email'], $data);
    }
});


/** Woo credits purchase */
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
    // Avoid duplicate sends
    if (get_transient('pm_vendor_approved_' . $vendor_id)) return;
    set_transient('pm_vendor_approved_' . $vendor_id, 1, 5 * MINUTE_IN_SECONDS);

    $u = get_user_by('id', $vendor_id);
    if (!$u) return;

    // Generate reset link
    $reset_link = '';
    if (function_exists('get_password_reset_key')) {
        $key = get_password_reset_key($u);
        if (!is_wp_error($key)) {
            $reset_link = network_site_url(
                'wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($u->user_login),
                'login'
            );
        }
    }

    // Premium Moving brand button style
    $button_html = $reset_link ? '
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:25px auto;text-align:center;">
        <tr>
            <td style="border-radius:6px;text-align:center;">
                <a href="' . esc_url($reset_link) . '"
                   style="background-color:#C68960;
                          color:#ffffff;
                          font-family:Arial,sans-serif;
                          font-size:16px;
                          font-weight:600;
                          text-decoration:none;
                          padding:10px 24px;
                          border-radius:6px;
                          display:inline-block;
                          line-height:20px;
                          vertical-align:middle;">
                    Set your password
                </a>
            </td>
        </tr>
    </table>' : '';

    // Subtle fallback link
    $login_fallback = '
        <p style="text-align:center;margin:15px 0;">
            <a href="' . esc_url(wp_login_url()) . '"
               style="color:#C68960;text-decoration:none;font-weight:500;">
               Log in manually
            </a>
        </p>';

    $data = [
        'vendor_name'    => $u->display_name,
        'vendor_email'   => $u->user_email,
        'reset_button'   => $button_html,
        'login_fallback' => $login_fallback,
        'site_name'      => get_bloginfo('name'),
        'site_url'       => home_url(),
        'dashboard_url'  => admin_url('admin.php?page=pm-leads')
    ];

    pm_leads_send_template('vendor_approved_vendor', $u->user_email, $data);
}

function pm_leads_maybe_warn_low_credits($vendor_id) {
    $bal = (int) get_user_meta($vendor_id, 'pm_credit_balance', true);
    if ($bal > 2) return;

    $u = get_user_by('id', $vendor_id);
    if (!$u) return;

    $data = [
        'vendor_name'          => $u->display_name,
        'vendor_email'         => $u->user_email,
        'vendor_credits'       => $bal,
        'low_credit_threshold' => 2
    ];

    pm_leads_send_template('low_credits_vendor', $data['vendor_email'], $data);
}

add_action('pm_leads_vendor_approved', 'pm_leads_mark_vendor_approved');
