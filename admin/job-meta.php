<?php
if (!defined('ABSPATH')) exit;

/**
 * Pretty Job Meta Box
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'pm_job_details',
        'Job Details',
        'pm_leads_job_meta_box_html',
        'pm_job',
        'normal',
        'high'
    );
});

function pm_leads_job_meta_box_html($post) {

    wp_nonce_field('pm_job_meta_save', 'pm_job_meta_nonce');

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

    // Fetch values
    $vals = [];
    foreach ($fields as $k => $label) {
        $vals[$k] = get_post_meta($post->ID, $k, true);
    }

    echo '<style>
        .pm-leads-field-group { display:flex; gap:20px; margin-bottom:15px; }
        .pm-leads-field { flex:1; }
        .pm-leads-field label { display:block; font-weight:600; margin-bottom:4px; }
        .pm-leads-field input,
        .pm-leads-field textarea { width:100%; max-width:100%; }
        textarea { min-height:70px; resize:vertical; }
    </style>';

    echo '<div class="pm-leads-meta">';

    // Move Details
    echo '<h2>Move Details</h2>';
    echo '<div class="pm-leads-field-group">
        <div class="pm-leads-field">
            <label>Current Postcode</label>
            <input name="current_postcode" value="'.esc_attr($vals['current_postcode']).'" />
        </div>
        <div class="pm-leads-field">
            <label>Current Address</label>
            <input name="current_address" value="'.esc_attr($vals['current_address']).'" />
        </div>
        <div class="pm-leads-field">
            <label>Bedrooms (Current)</label>
            <input type="number" name="bedrooms_current" value="'.esc_attr($vals['bedrooms_current']).'" />
        </div>
    </div>';

    echo '<div class="pm-leads-field-group">
        <div class="pm-leads-field">
            <label>New Postcode</label>
            <input name="new_postcode" value="'.esc_attr($vals['new_postcode']).'" />
        </div>
        <div class="pm-leads-field">
            <label>New Address</label>
            <input name="new_address" value="'.esc_attr($vals['new_address']).'" />
        </div>
        <div class="pm-leads-field">
            <label>Bedrooms (New)</label>
            <input type="number" name="bedrooms_new" value="'.esc_attr($vals['bedrooms_new']).'" />
        </div>
    </div>';

    // Customer
    echo '<h2>Customer</h2>';
    echo '<div class="pm-leads-field-group">
        <div class="pm-leads-field">
            <label>Name</label>
            <input name="customer_name" value="'.esc_attr($vals['customer_name']).'" />
        </div>
        <div class="pm-leads-field">
            <label>Email</label>
            <input name="customer_email" value="'.esc_attr($vals['customer_email']).'" />
        </div>
        <div class="pm-leads-field">
            <label>Phone</label>
            <input name="customer_phone" value="'.esc_attr($vals['customer_phone']).'" />
        </div>
    </div>';

    echo '<div class="pm-leads-field-group">
        <div class="pm-leads-field">
            <label>Customer Message</label>
            <textarea name="customer_message">'.esc_textarea($vals['customer_message']).'</textarea>
        </div>
    </div>';

    // Purchases
    echo '<h2>Status</h2>';
    echo '<div class="pm-leads-field-group">
        <div class="pm-leads-field">
            <label>Purchases</label>
            <input type="number" name="purchase_count" value="'.esc_attr($vals['purchase_count']).'" />
        </div>
    </div>';

    echo '</div>';
}

/**
 * Save handler
 */
add_action('save_post_pm_job', function ($post_id) {

    if (!isset($_POST['pm_job_meta_nonce']) || !wp_verify_nonce($_POST['pm_job_meta_nonce'], 'pm_job_meta_save'))
        return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;

    if (!current_user_can('edit_post', $post_id))
        return;

    $fields = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_message',
        'current_postcode',
        'current_address',
        'bedrooms_current',
        'new_postcode',
        'new_address',
        'bedrooms_new',
        'purchase_count'
    ];

    foreach ($fields as $key) {
        if (isset($_POST[$key])) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }
});
