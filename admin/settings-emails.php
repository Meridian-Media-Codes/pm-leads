<?php
if (!defined('ABSPATH')) exit;

function pm_leads_render_email_settings() {

    echo '<h2>Email Templates</h2>';
    echo '<p>Configure the email content sent to customers and vendors.</p>';

    echo '<ul style="margin:20px 0;">';
    echo '  <li>• New Lead → Customer</li>';
    echo '  <li>• New Lead → Vendors (in range)</li>';
    echo '  <li>• Lead Purchased → Vendor</li>';
    echo '  <li>• Lead Purchased → Customer</li>';
    echo '  <li>• Credits Purchased → Vendor</li>';
    echo '  <li>• Low Credits Warning → Vendor</li>';
    echo '  <li>• Vendor Approved → Vendor</li>';
    echo '</ul>';

    echo '<p>Template editing options coming next...</p>';
}
