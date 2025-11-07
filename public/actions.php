<?php
if (!defined('ABSPATH')) exit;

/**
 * URLs:
 *  - admin_url('admin-post.php?action=pm_buy_lead_credit&job_id=123&_wpnonce=...')
 *  - admin_url('admin-post.php?action=pm_buy_lead_cash&job_id=123&_wpnonce=...')
 *  - admin_url('admin-post.php?action=pm_decline_lead&job_id=123&_wpnonce=...')
 */

add_action('admin_post_pm_buy_lead_credit', 'pm_buy_lead_credit');
function pm_buy_lead_credit() {
    if (!is_user_logged_in()) wp_die('Login required');
    $user = wp_get_current_user();
    if (!in_array('pm_vendor', (array)$user->roles, true)) wp_die('Vendor role required');

    $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
    if (!$job_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pm_lead_'.$job_id)) wp_die('Bad nonce');

    // Limit check
    $opts = pm_leads_get_options();
    $limit = isset($opts['purchase_limit']) ? intval($opts['purchase_limit']) : 5;
    $count = intval(get_post_meta($job_id, 'purchase_count', true));
    if ($count >= $limit) return pm_actions_back('Lead is sold out.');

    // Already purchased?
    $buyers = get_post_meta($job_id, 'pm_purchased_by', true);
    if (!is_array($buyers)) $buyers = [];
    if (in_array($user->ID, $buyers, true)) return pm_actions_back('You already own this lead.');

    // Credit check
    $bal = intval(get_user_meta($user->ID, 'pm_credit_balance', true));
    if ($bal <= 0) return pm_actions_back('Not enough credits.');

    // Deduct and mark purchased
    update_user_meta($user->ID, 'pm_credit_balance', $bal - 1);
    pm_mark_job_purchased_by($job_id, $user->ID);

    // TODO: send email with full details to vendor

    return pm_actions_back('Lead unlocked with 1 credit.', true);
}

add_action('admin_post_pm_buy_lead_cash', 'pm_buy_lead_cash');
function pm_buy_lead_cash() {
    if (!is_user_logged_in()) wp_die('Login required');
    $user = wp_get_current_user();
    if (!in_array('pm_vendor', (array)$user->roles, true)) wp_die('Vendor role required');

    $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
    if (!$job_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pm_lead_'.$job_id)) wp_die('Bad nonce');

    // Find the product created for this job
    $product_id = intval(get_post_meta($job_id, '_pm_lead_product_id', true));
    if (!$product_id) return pm_actions_back('Lead product not found.');

    // Add to cart and go to checkout
    if (!function_exists('WC')) return pm_actions_back('WooCommerce not available.');
    WC()->cart->empty_cart(); // optional
    $added = WC()->cart->add_to_cart($product_id, 1, 0, [], ['_pm_job_id' => $job_id]);
    if (!$added) return pm_actions_back('Could not add to cart.');

    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

add_action('admin_post_pm_decline_lead', 'pm_decline_lead');
function pm_decline_lead() {
    if (!is_user_logged_in()) wp_die('Login required');
    $user = wp_get_current_user();
    if (!in_array('pm_vendor', (array)$user->roles, true)) wp_die('Vendor role required');

    $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
    if (!$job_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pm_lead_'.$job_id)) wp_die('Bad nonce');

    $declined = get_user_meta($user->ID, 'pm_declined_jobs', true);
    if (!is_array($declined)) $declined = [];
    if (!in_array($job_id, $declined, true)) {
        $declined[] = $job_id;
        update_user_meta($user->ID, 'pm_declined_jobs', $declined);
    }

    return pm_actions_back('Lead declined.', true);
}

// Helper redirect back
function pm_actions_back($msg, $ok=false) {
    $ref = wp_get_referer();
    if (!$ref) $ref = home_url('/');
    $ref = add_query_arg([
        'pm_notice' => rawurlencode($msg),
        'pm_ok'     => $ok ? '1' : '0',
    ], $ref);
    wp_safe_redirect($ref);
    exit;
}
