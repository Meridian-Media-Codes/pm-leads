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

// [pm_vendor_dashboard]
function pm_vendor_dashboard_shortcode() {

    // Must be logged in
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your vendor dashboard.</p>';
    }

    $user = wp_get_current_user();

    // Allow admins to view the dashboard
    if (!in_array('pm_vendor', (array)$user->roles, true) && !current_user_can('administrator')) {
        return '<p>You must be a vendor to view this page.</p>';
    }

    ob_start();

    // Get lead data
    $available = pm_vendor_get_available_leads($user->ID);
    $purchased = pm_vendor_get_purchased_leads($user->ID);
    ?>

    <div class="pm-vendor-dashboard">

        <h2>New Leads</h2>
        <?php if (!$available) : ?>
            <p>No new leads available.</p>
        <?php else: ?>
            <table class="widefat pm-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Bedrooms</th>
                        <th>Distance</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($available as $row) :
                        $id = $row['job_id'];
                        $dist = round($row['distance'], 1);

                        $from = get_post_meta($id, 'current_postcode', true);
                        $to   = get_post_meta($id, 'new_postcode', true);
                        $bed  = get_post_meta($id, 'bedrooms_new', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($from); ?></td>
                        <td><?php echo esc_html($to); ?></td>
                        <td><?php echo esc_html($bed); ?></td>
                        <td><?php echo esc_html($dist . ' mi'); ?></td>
                        <td>
                            <button disabled>Buy Lead</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>


        <h2 style="margin-top:40px;">Purchased Leads</h2>
        <?php if (!$purchased) : ?>
            <p>No purchased leads.</p>
        <?php else: ?>
            <table class="widefat pm-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Bedrooms</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchased as $j) :
                        $from = get_post_meta($j->ID, 'current_postcode', true);
                        $to   = get_post_meta($j->ID, 'new_postcode', true);
                        $bed  = get_post_meta($j->ID, 'bedrooms_new', true);
                        $msg  = get_post_meta($j->ID, 'customer_message', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($from); ?></td>
                        <td><?php echo esc_html($to); ?></td>
                        <td><?php echo esc_html($bed); ?></td>
                        <td><?php echo esc_html($msg); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

    <?php
    return ob_get_clean();
}

add_shortcode('pm_vendor_dashboard', 'pm_vendor_dashboard_shortcode');
