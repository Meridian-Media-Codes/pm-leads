<?php
if (!defined('ABSPATH')) exit;

/**
 * PM Leads – Admin Menus + Vendor Dashboard
 *
 * Vendor dashboard columns (no beds/price):
 *  - From
 *  - To
 *  - Distance from you (mi)
 *  - Total job distance (mi)
 *  - Purchases left
 *  - Actions: Buy with credits / Buy now
 */

/* ---------------------------
 * Small safe helpers (guards)
 * --------------------------- */

/** Current vendor user ID or 0 (mirror of credits.php) */
if (!function_exists('pm_leads_current_vendor_id')) {
    function pm_leads_current_vendor_id() {
        $uid = get_current_user_id();
        if (!$uid) return 0;
        $u = get_user_by('id', $uid);
        if (!$u || !in_array('pm_vendor', (array)$u->roles, true)) return 0;
        return (int) $uid;
    }
}

/** Options helper with default limit=5 (mirror of credits.php) */
if (!function_exists('pm_leads_opts')) {
    function pm_leads_opts() {
        $limit = 5;
        if (function_exists('pm_leads_get_options')) {
            $o = pm_leads_get_options();
            if (isset($o['purchase_limit'])) {
                $limit = max(1, (int)$o['purchase_limit']);
            }
        }
        return ['purchase_limit' => $limit];
    }
}

/** Get Woo product id linked to a job (mirror of credits.php) */
if (!function_exists('pm_leads_get_job_product_id')) {
    function pm_leads_get_job_product_id($job_id) {
        return absint(get_post_meta($job_id, '_pm_wc_product_id', true));
    }
}

/** Purchase count (mirror of credits.php) */
if (!function_exists('pm_leads_get_purchase_count')) {
    function pm_leads_get_purchase_count($job_id) {
        $v = get_post_meta($job_id, 'purchase_count', true);
        return ($v === '' ? 0 : (int)$v);
    }
}

/** Has vendor already bought? (mirror of credits.php) */
if (!function_exists('pm_leads_vendor_has_bought')) {
    function pm_leads_vendor_has_bought($job_id, $vendor_id) {
        $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
        if (!is_array($buyers)) $buyers = [];
        return in_array((int)$vendor_id, $buyers, true);
    }
}

/** Haversine (use existing if present) */
if (!function_exists('pm_leads_distance_mi')) {
    function pm_leads_distance_mi($lat1, $lng1, $lat2, $lng2) {
        if ($lat1 === '' || $lng1 === '' || $lat2 === '' || $lng2 === '') return '';
        $earth = 3958.8; // miles
        $lat1 = deg2rad((float)$lat1);
        $lat2 = deg2rad((float)$lat2);
        $lng1 = deg2rad((float)$lng1);
        $lng2 = deg2rad((float)$lng2);
        $d = 2 * asin(
            sqrt(
                pow(sin(($lat1 - $lat2) / 2), 2) +
                cos($lat1) * cos($lat2) * pow(sin(($lng1 - $lng2) / 2), 2)
            )
        );
        return $earth * $d;
    }
}

/* ---------------------------
 * Admin menu registration
 * --------------------------- */
add_action('admin_menu', function () {

    add_menu_page(
        __('PM Leads', 'pm-leads'),
        __('PM Leads', 'pm-leads'),
        'read', // let vendors open dashboard (render checks will split views)
        'pm-leads',
        'pm_leads_render_dashboard',
        'dashicons-admin-site-alt3',
        26
    );

    add_submenu_page(
        'pm-leads',
        __('Dashboard', 'pm-leads'),
        __('Dashboard', 'pm-leads'),
        'read',
        'pm-leads',
        'pm_leads_render_dashboard'
    );

    // Admin-only submenus
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'pm-leads',
            __('Vendors', 'pm-leads'),
            __('Vendors', 'pm-leads'),
            'manage_options',
            'pm-leads-vendors',
            'pm_leads_render_vendors'
        );

        add_submenu_page(
            'pm-leads',
            __('Jobs', 'pm-leads'),
            __('Jobs', 'pm-leads'),
            'manage_options',
            'pm-leads-jobs',
            'pm_leads_render_jobs'
        );

        add_submenu_page(
            'pm-leads',
            __('Settings', 'pm-leads'),
            __('Settings', 'pm-leads'),
            'manage_options',
            'pm-leads-settings',
            'pm_leads_render_settings'
        );
    }
});

/* ---------------------------
 * Dashboard
 * --------------------------- */
function pm_leads_render_dashboard() {
    $vendor_id = pm_leads_current_vendor_id();

    // Admins see a simple note and a link to Jobs
    if (!$vendor_id) {
        echo '<div class="wrap"><h1>PM Leads</h1>';
        echo '<p>This is the vendor dashboard. As an admin you can manage <a href="' . esc_url(admin_url('admin.php?page=pm-leads-jobs')) . '">Jobs</a> and <a href="' . esc_url(admin_url('admin.php?page=pm-leads-vendors')) . '">Vendors</a>.</p>';
        echo '</div>';
        return;
    }

    // Vendor view (no beds/price)
    $opts  = pm_leads_opts();
    $limit = (int) $opts['purchase_limit'];

    $v_lat = get_user_meta($vendor_id, 'pm_vendor_lat', true);
    $v_lng = get_user_meta($vendor_id, 'pm_vendor_lng', true);

    // Pull recent/available jobs. Adjust as needed.
    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    echo '<div class="wrap">';
    echo '<h1>Available leads</h1>';

    if ($v_lat === '' || $v_lng === '') {
        echo '<div class="notice notice-warning"><p>Please add your business postcode on your profile so we can calculate distance from you.</p></div>';
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>From</th>';
    echo '<th>To</th>';
    echo '<th>Distance from you (mi)</th>';
    echo '<th>Total job distance (mi)</th>';
    echo '<th>Purchases left</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    if ($jobs) {
        foreach ($jobs as $j) {
            $job_id   = $j->ID;
            $from_pc  = get_post_meta($job_id, 'current_postcode', true);
            $to_pc    = get_post_meta($job_id, 'new_postcode', true);

            $from_lat = get_post_meta($job_id, 'pm_job_from_lat', true);
            $from_lng = get_post_meta($job_id, 'pm_job_from_lng', true);
            $to_lat   = get_post_meta($job_id, 'pm_job_to_lat', true);
            $to_lng   = get_post_meta($job_id, 'pm_job_to_lng', true);

            $dist_from_vendor = ($v_lat !== '' && $v_lng !== '' && $from_lat !== '' && $from_lng !== '')
                ? pm_leads_distance_mi($v_lat, $v_lng, $from_lat, $from_lng)
                : '';

            $dist_total = ($from_lat !== '' && $from_lng !== '' && $to_lat !== '' && $to_lng !== '')
                ? pm_leads_distance_mi($from_lat, $from_lng, $to_lat, $to_lng)
                : '';

            $count  = pm_leads_get_purchase_count($job_id);
            $left   = max(0, $limit - $count);

            // Already bought or sold out?
            $already = pm_leads_vendor_has_bought($job_id, $vendor_id);
            $soldout = ($left <= 0);

            // Actions
            $actions = [];
            if ($already) {
                $actions[] = '<span class="button disabled">You own this</span>';
            } elseif ($soldout) {
                $actions[] = '<span class="button disabled">Sold out</span>';
            } else {
                // Buy with credits
                $credits_link = wp_nonce_url(
                    admin_url('admin-post.php?action=pm_buy_lead_credits&job_id=' . intval($job_id)),
                    'pm_buy_lead_credits'
                );
                $actions[] = '<a class="button button-primary" href="' . esc_url($credits_link) . '">Buy with credits</a>';

                // Buy now (Woo)
                $pid = pm_leads_get_job_product_id($job_id);
                if ($pid) {
                    $buy_now = add_query_arg(['add-to-cart' => $pid, 'quantity' => 1], home_url('/'));
                    $actions[] = '<a class="button" href="' . esc_url($buy_now) . '">Buy now</a>';
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($from_pc) . '</td>';
            echo '<td>' . esc_html($to_pc) . '</td>';
            echo '<td>' . ($dist_from_vendor !== '' ? esc_html(number_format((float)$dist_from_vendor, 1)) : '<em>—</em>') . '</td>';
            echo '<td>' . ($dist_total !== '' ? esc_html(number_format((float)$dist_total, 1)) : '<em>—</em>') . '</td>';
            echo '<td>' . esc_html($left) . '</td>';
            echo '<td>' . implode(' ', $actions) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No leads yet.</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/* ---------------------------
 * Vendors (admin)
 * --------------------------- */
function pm_leads_render_vendors() {
    if (!current_user_can('manage_options')) return;

    if (!empty($_POST['pm_vendor_nonce']) && wp_verify_nonce($_POST['pm_vendor_nonce'], 'pm_vendor_save')) {
        $vid = absint($_POST['vendor_id']);
        if ($vid) {

            if (!empty($_POST['vendor_email'])) {
                wp_update_user([
                    'ID'         => $vid,
                    'user_email' => sanitize_email($_POST['vendor_email'])
                ]);
            }

            $fields = [
                'company_name','first_name','last_name','contact_number',
                'business_postcode','service_radius','years_in_business'
            ];

            foreach ($fields as $k) {
                if (isset($_POST[$k])) {
                    update_user_meta($vid, $k, sanitize_text_field($_POST[$k]));
                }
            }

            if (isset($_POST['pm_credit_balance'])) {
                update_user_meta($vid, 'pm_credit_balance', intval($_POST['pm_credit_balance']));
            }

            if (!empty($_POST['business_postcode']) && function_exists('pm_leads_geocode')) {
                $coords = pm_leads_geocode(sanitize_text_field($_POST['business_postcode']));
                if (!empty($coords['lat']) && !empty($coords['lng'])) {
                    update_user_meta($vid, 'pm_vendor_lat', $coords['lat']);
                    update_user_meta($vid, 'pm_vendor_lng', $coords['lng']);
                }
            }

            echo '<div class="updated notice"><p>Vendor updated.</p></div>';
        }
    }

    if (!empty($_GET['vendor_id'])) {

        $uid = absint($_GET['vendor_id']);
        $u = get_user_by('id', $uid);

        if (!$u) {
            echo '<div class="wrap"><h1>Vendor not found</h1></div>';
            return;
        }

        $fields = [
            'company_name'      => 'Company Name',
            'first_name'        => 'First Name',
            'last_name'         => 'Last Name',
            'vendor_email'      => 'Vendor Email',
            'contact_number'    => 'Contact Number',
            'business_postcode' => 'Business Postcode',
            'service_radius'    => 'Service Radius (miles)',
            'years_in_business' => 'Years In Business',
            'insurance_docs'    => 'Insurance Docs'
        ];

        $vals = [];
        foreach ($fields as $k => $label) {
            $vals[$k] = ($k === 'vendor_email')
                ? $u->user_email
                : get_user_meta($u->ID, $k, true);
        }

        $credits = intval(get_user_meta($u->ID, 'pm_credit_balance', true));

        echo '<div class="wrap"><h1>Vendor Profile</h1>';

        echo '<form method="post">';
        wp_nonce_field('pm_vendor_save','pm_vendor_nonce');
        echo '<input type="hidden" name="vendor_id" value="' . intval($u->ID) . '"/>';

        echo '<table class="form-table"><tbody>';

        foreach ($fields as $k => $label) {
            echo '<tr><th style="width:220px;"><label>' . esc_html($label) . '</label></th><td>';

            if ($k === 'insurance_docs' && !empty($vals[$k])) {
                $url = is_array($vals[$k]) ? reset($vals[$k]) : $vals[$k];
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View document</a>';
            } else {
                $type = ($k === 'service_radius' || $k === 'years_in_business')
                    ? 'number' : 'text';

                echo '<input type="' . esc_attr($type) . '" 
                        name="' . esc_attr($k) . '" 
                        value="' . esc_attr($vals[$k]) . '" 
                        class="regular-text" />';
            }

            echo '</td></tr>';
        }

        echo '<tr><th><label>Credits</label></th><td>
                <input type="number" name="pm_credit_balance" value="' . esc_attr($credits) . '" class="small-text" />
              </td></tr>';

        echo '<tr><th>Registered</th><td>' . esc_html($u->user_registered) . '</td></tr>';

        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">Save</button>
                <a class="button" href="'.esc_url(admin_url('admin.php?page=pm-leads-vendors')).'">Back to Vendors</a>
              </p>';

        echo '</form></div>';
        return;
    }

    $vendors = get_users(['role'=>'pm_vendor','number'=>500]);

    echo '<div class="wrap"><h1>Vendors</h1>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Company/User</th><th>Email</th><th>Credits</th><th>Registered</th><th>Profile</th>';
    echo '</tr></thead><tbody>';

    if ($vendors) {
        foreach ($vendors as $v) {
            $credits = intval(get_user_meta($v->ID,'pm_credit_balance',true));
            $profile_url = esc_url(admin_url('admin.php?page=pm-leads-vendors&vendor_id='.intval($v->ID)));

            echo '<tr>';
            echo '<td>' . esc_html($v->display_name) . '</td>';
            echo '<td>' . esc_html($v->user_email) . '</td>';
            echo '<td>' . esc_html($credits) . '</td>';
            echo '<td>' . esc_html($v->user_registered) . '</td>';
            echo '<td><a class="button button-small" href="' . $profile_url . '">Open</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No vendors found.</td></tr>';
    }

    echo '</tbody></table></div>';
}

/* ---------------------------
 * Jobs (admin)
 * --------------------------- */
function pm_leads_render_jobs() {
    if (!current_user_can('manage_options')) return;

    // Save handler for single job view
    if (isset($_POST['pm_job_nonce']) && wp_verify_nonce($_POST['pm_job_nonce'], 'pm_job_save')) {
        $job_id = absint($_POST['job_id'] ?? 0);
        if ($job_id) {
            $fields = [
                'customer_name','customer_email','customer_phone','customer_message',
                'current_postcode','current_address','bedrooms_current',
                'new_postcode','new_address','bedrooms_new','purchase_count'
            ];
            foreach ($fields as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($job_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
            $current = sanitize_text_field($_POST['current_postcode'] ?? '');
            $new     = sanitize_text_field($_POST['new_postcode'] ?? '');

            if ($current && function_exists('pm_job_geocode')) {
                pm_job_geocode($job_id, $current, 'pm_job_from');
            }
            if ($new && function_exists('pm_job_geocode')) {
                pm_job_geocode($job_id, $new, 'pm_job_to');
            }

            echo '<div class="updated notice"><p>Job updated.</p></div>';
        }
    }

    // Single job view
    if (!empty($_GET['job_id'])) {
        $job_id = absint($_GET['job_id']);
        $job = get_post($job_id);
        if (!$job || $job->post_type !== 'pm_job') {
            echo '<div class="wrap"><h1>Job not found</h1></div>';
            return;
        }

        $fields = [
            'customer_name'     => 'Customer Name',
            'customer_email'    => 'Customer Email',
            'customer_phone'    => 'Customer Phone',
            'customer_message'  => 'Customer Message',
            'current_postcode'  => 'Current Postcode',
            'current_address'   => 'Current Address',
            'bedrooms_current'  => 'Current Bedrooms',
            'new_postcode'      => 'New Postcode',
            'new_address'       => 'New Address',
            'bedrooms_new'      => 'New Bedrooms',
            'purchase_count'    => 'Purchases'
        ];
        $vals = [];
        foreach ($fields as $k => $label) {
            $vals[$k] = get_post_meta($job_id, $k, true);
        }

        echo '<div class="wrap"><h1>Job #' . intval($job_id) . '</h1>';
        echo '<form method="post">';
        wp_nonce_field('pm_job_save', 'pm_job_nonce');
        echo '<input type="hidden" name="job_id" value="' . intval($job_id) . '"/>';

        echo '<table class="form-table"><tbody>';
        foreach ($fields as $k => $label) {
            $val = $vals[$k];
            echo '<tr><th scope="row"><label>' . esc_html($label) . '</label></th><td>';
            if ($k === 'customer_message' || $k === 'current_address' || $k === 'new_address') {
                echo '<textarea name="' . esc_attr($k) . '" rows="3" class="large-text">' . esc_textarea($val) . '</textarea>';
            } else {
                $type = ($k === 'bedrooms_current' || $k === 'bedrooms_new' || $k === 'purchase_count') ? 'number' : 'text';
                echo '<input type="' . $type . '" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '" class="regular-text" />';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=pm-leads-jobs')) . '">Back to Jobs</a></p>';
        echo '</form></div>';
        return;
    }

    // List view (admin)
    $jobs = get_posts([
        'post_type'      => 'pm_job',
        'posts_per_page' => 100,
        'post_status'    => 'any',
    ]);

    echo '<div class="wrap"><h1>Jobs</h1>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>ID</th><th>From</th><th>To</th><th>Status</th><th>Purchases</th><th>Linked product</th>';
    echo '</tr></thead><tbody>';

    if ($jobs) {
        foreach ($jobs as $j) {
            $view_url  = admin_url('admin.php?page=pm-leads-jobs&job_id=' . intval($j->ID));
            $from      = get_post_meta($j->ID, 'current_postcode', true);
            $to        = get_post_meta($j->ID, 'new_postcode', true);
            $purchases = pm_leads_get_purchase_count($j->ID);

            $status_terms = wp_get_post_terms($j->ID, 'pm_job_status', ['fields' => 'names']);
            $status       = $status_terms ? implode(', ', $status_terms) : 'available';

            $pid = pm_leads_get_job_product_id($j->ID);

            echo '<tr>';
            echo '<td><a href="' . esc_url($view_url) . '">#' . intval($j->ID) . '</a></td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($from) . '</a></td>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($to) . '</a></td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html((int)$purchases) . '</td>';
            echo '<td>' . ($pid ? ('#' . intval($pid)) : '<em>—</em>') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No jobs yet.</td></tr>';
    }
    echo '</tbody></table></div>';
}

/* ---------------------------
 * Settings (admin)
 * --------------------------- */
function pm_leads_render_settings() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap"><h1>Settings</h1>';

    if (!function_exists('settings_fields')) {
        echo '<p>Settings API not available.</p></div>';
        return;
    }

    echo '<form method="post" action="options.php">';
    settings_fields('pm_leads_options_group');
    do_settings_sections('pm_leads_settings');
    submit_button();
    echo '</form></div>';
}
