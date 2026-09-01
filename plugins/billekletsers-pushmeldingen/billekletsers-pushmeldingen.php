<?php
/**
 * Plugin Name: Billekletsers Pushmeldingen
 * Plugin URI: https://www.cvdebillekletsers.nl/
 * Description: Stuurt persoonlijke pushmeldingen voor deadlines uit de Billekletsers Jaarplanner naar de geïnstalleerde telefoonapp.
 * Version: 1.1.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: C.V. De Billekletsers
 * Text Domain: billekletsers-pushmeldingen
 */

if (!defined('ABSPATH')) { exit; }

define('BKPP_VERSION', '1.1.1');
define('BKPP_FILE', __FILE__);
define('BKPP_DIR', plugin_dir_path(__FILE__));
define('BKPP_URL', plugin_dir_url(__FILE__));

require_once BKPP_DIR . 'includes/class-bkpp-plugin.php';

register_activation_hook(__FILE__, array('BKPP_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('BKPP_Plugin', 'deactivate'));

BKPP_Plugin::instance();
