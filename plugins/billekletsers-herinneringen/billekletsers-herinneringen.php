<?php
/**
 * Plugin Name: Billekletsers Herinneringsmails
 * Description: Stuurt automatische herinneringsmails voor deadlines in de Billekletsers-jaarplanning, zonder planningdata te importeren of te wijzigen.
 * Version: 1.4.1
 * Author: C.V. De Billekletsers
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: billekletsers-herinneringen
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BKPR_VERSION', '1.4.1');
define('BKPR_FILE', __FILE__);
define('BKPR_DIR', plugin_dir_path(__FILE__));
define('BKPR_URL', plugin_dir_url(__FILE__));

require_once BKPR_DIR . 'includes/class-bkpr-plugin.php';

register_activation_hook(__FILE__, array('BKPR_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('BKPR_Plugin', 'deactivate'));

BKPR_Plugin::instance();
