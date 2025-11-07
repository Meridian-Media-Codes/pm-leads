<?php
if (!defined('ABSPATH')) exit;

/**
 * Register vendor roles
 */
function pm_leads_register_roles() {

    // Pending vendors (cannot buy leads yet)
    add_role(
        'pm_vendor_pending',
        __('Vendor (Pending)', 'pm-leads'),
        [
            'read' => false
        ]
    );

    // Approved vendors
    add_role(
        'pm_vendor',
        __('Vendor', 'pm-leads'),
        [
            'read' => true
        ]
    );
}
add_action('init', 'pm_leads_register_roles');


/**
 * Ensure roles exist even if removed manually
 */
add_action('init', function () {
    if (!get_role('pm_vendor_pending') || !get_role('pm_vendor')) {
        pm_leads_register_roles();
    }
});


/**
 * PM Vendor extra fields
 */
add_action('show_user_profile',  'pm_vendor_extra_fields');
add_action('edit_user_profile',  'pm_vendor_extra_fields');

function pm_vendor_extra_fields($user) {

    // Only show for approved vendors
    if (!in_array('pm_vendor', (array)$user->roles, true)) return;

    $postcode = get_user_meta($user->ID, 'pm_vendor_postcode', true);
    $lat      = get_user_meta($user->ID, 'pm_vendor_lat', true);
    $lng      = get_user_meta($user->ID, 'pm_vendor_lng', true);
    $credits  = get_user_meta($user->ID, 'pm_credit_balance', true);
    $credits  = $credits === '' ? 0 : intval($credits);

    ?>
    <h2>PM Vendor Details</h2>
    <table class="form-table">
        <tr>
            <th><label for="pm_vendor_postcode">Base postcode</label></th>
            <td>
                <input type="text" name="pm_vendor_postcode"
                       value="<?php echo esc_attr($postcode); ?>"
                       class="regular-text" />
            </td>
        </tr>

        <tr>
            <th><label>Latitude</label></th>
            <td><?php echo esc_html($lat); ?></td>
        </tr>

        <tr>
            <th><label>Longitude</label></th>
            <td><?php echo esc_html($lng); ?></td>
        </tr>

        <tr>
            <th><label for="pm_credit_balance">Credits</label></th>
            <td>
                <input type="number" name="pm_credit_balance"
                       value="<?php echo esc_attr($credits); ?>"
                       class="small-text" />
            </td>
        </tr>
    </table>
    <?php
}


/**
 * Save PM Vendor fields
 */
add_action('personal_options_update',    'pm_vendor_save_fields');
add_action('edit_user_profile_update',  'pm_vendor_save_fields');

function pm_vendor_save_fields($user_id) {

    if (!current_user_can('edit_user', $user_id)) return;

    // Store postcode
    if (isset($_POST['pm_vendor_postcode'])) {
        $postcode = sanitize_text_field($_POST['pm_vendor_postcode']);
        update_user_meta($user_id, 'pm_vendor_postcode', $postcode);

        if (function_exists('pm_leads_geocode')) {
            $coords = pm_leads_geocode($postcode);
            if ($coords && !empty($coords['lat']) && !empty($coords['lng'])) {
                update_user_meta($user_id, 'pm_vendor_lat',  $coords['lat']);
                update_user_meta($user_id, 'pm_vendor_lng',  $coords['lng']);
            }
        }
    }

    // Store credits
    if (isset($_POST['pm_credit_balance'])) {
        update_user_meta($user_id, 'pm_credit_balance', intval($_POST['pm_credit_balance']));
    }
}
