<?php
if ( ! defined('ABSPATH') ) exit;

// [pm_leads_form]
function pm_leads_form_shortcode() {
    ob_start();
    ?>
    <form method="post" class="pm-leads-form">
        <?php wp_nonce_field('pm_leads_submit', 'pm_leads_nonce'); ?>
        <p>
            <label>Current Property Postcode *</label><br/>
            <input type="text" name="current_postcode" required />
        </p>
        <p>
            <label>New Property Postcode *</label><br/>
            <input type="text" name="new_postcode" required />
        </p>
        <p>
            <label>Number of Bedrooms (New House) *</label><br/>
            <input type="number" name="bedrooms_new" min="0" required />
        </p>
        <p>
            <label>Number of Bedrooms (Current House) *</label><br/>
            <input type="number" name="bedrooms_current" min="0" required />
        </p>
        <p>
            <label>Email *</label><br/>
            <input type="email" name="customer_email" required />
        </p>
        <p>
            <label>Message</label><br/>
            <textarea name="customer_message" rows="4"></textarea>
        </p>
        <p>
            <button type="submit" name="pm_leads_submit" value="1">Request quote</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_leads_form', 'pm_leads_form_shortcode');

// [pm_vendor_dashboard] placeholder for future
function pm_vendor_dashboard_shortcode() {
    if (! is_user_logged_in() || ! current_user_can('read')) {
        return '<p>Please log in to view your vendor dashboard.</p>';
    }
    $user = wp_get_current_user();
    if (! in_array('pm_vendor', (array)$user->roles, true)) {
        return '<p>You must be a vendor to view this page.</p>';
    }
    ob_start();
    echo '<div class="pm-vendor-dashboard">';
    echo '<h2>Vendor dashboard</h2>';
    echo '<p>This is a placeholder. New Leads and Purchased Leads will appear here.</p>';
    echo '</div>';
    return ob_get_clean();
}
add_shortcode('pm_vendor_dashboard', 'pm_vendor_dashboard_shortcode');
