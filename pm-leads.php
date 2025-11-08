<?php
/**
 * Plugin Name: PM Leads
 * Description: Lead generation and vendor distribution for moving companies. Integrates with WooCommerce. Scaffold v0.2.0
 * Version: 0.2.0
 * Author: Meridian Media
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: pm-leads
 */
if ( ! defined('ABSPATH') ) exit;

define('PM_LEADS_VERSION', '0.2.0');
define('PM_LEADS_FILE', __FILE__);
define('PM_LEADS_DIR', plugin_dir_path(__FILE__));
define('PM_LEADS_URL', plugin_dir_url(__FILE__));

// Includes
require_once PM_LEADS_DIR . 'includes/roles.php';
require_once PM_LEADS_DIR . 'includes/cpt-job.php';
require_once PM_LEADS_DIR . 'includes/options.php';
require_once PM_LEADS_DIR . 'admin/menu.php';
require_once PM_LEADS_DIR . 'public/shortcodes.php';
require_once PM_LEADS_DIR . 'public/form-handler.php';
require_once PM_LEADS_DIR . 'includes/woo-lead-product.php';
require_once PM_LEADS_DIR . 'includes/geo.php';
require_once PM_LEADS_DIR . 'public/dashboard.php';
require_once PM_LEADS_DIR . 'includes/credits.php';
require_once PM_LEADS_DIR . 'public/actions.php';
require_once PM_LEADS_DIR . 'includes/integrations/fluentforms.php';
require_once PM_LEADS_DIR . 'admin/job-meta.php';
require_once PM_LEADS_DIR . 'includes/integrations/fluentforms-vendor.php';
require_once __DIR__ . '/includes/email-helpers.php';


register_activation_hook(__FILE__, function () {
    pm_leads_register_role();
    pm_leads_register_cpt_job();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('pm-leads', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
