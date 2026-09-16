<?php
/**
 * Plugin Name: Billekletsers Jaarplanner
 * Plugin URI: https://www.cvdebillekletsers.nl/
 * Description: Centrale functionaliteit voor de interne jaarplanning, taken, commissies, documenten, frontend bewerken en seizoenswissels.
 * Version: 1.5.7
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: C.V. De Billekletsers
 * Text Domain: billekletsers-jaarplanner
 */

if (!defined('ABSPATH')) { exit; }

final class BKP_Core_Bootstrap {
    const VERSION = '1.5.7';
    const SCHEMA_VERSION = '6.1.0';

    public static function init() {
        add_action('after_setup_theme', array(__CLASS__, 'boot'), 0);
    }

    public static function activate() {
        update_option('blog_public', '0');
        update_option('bkp_core_version', self::VERSION, false);
        // Bewust geen import, synchronisatie of wijziging van bestaande planningdata.
    }

    public static function boot() {
        // De oude alles-in-een themaversie definieert dezelfde bkp_* functies.
        // Tijdens de overstap wachten we daarom totdat het nieuwe UI-thema actief is.
        if (function_exists('bkp_register_content_types')) {
            add_action('admin_notices', array(__CLASS__, 'legacy_theme_notice'));
            return;
        }

        define('BKP_CORE_VERSION', self::VERSION);
        define('BKP_CORE_SCHEMA_VERSION', self::SCHEMA_VERSION);
        define('BKP_CORE_FILE', __FILE__);
        define('BKP_CORE_DIR', plugin_dir_path(__FILE__));
        define('BKP_CORE_URI', plugin_dir_url(__FILE__));

        require_once BKP_CORE_DIR . 'includes/core.php';
        require_once BKP_CORE_DIR . 'includes/projects.php';
        require_once BKP_CORE_DIR . 'includes/inventory.php';
        require_once BKP_CORE_DIR . 'includes/admin.php';
        require_once BKP_CORE_DIR . 'includes/frontend-editor.php';
        require_once BKP_CORE_DIR . 'includes/season-rollover.php';
        require_once BKP_CORE_DIR . 'includes/responsibilities.php';
        require_once BKP_CORE_DIR . 'includes/responsibility-detail.php';
        require_once BKP_CORE_DIR . 'includes/registration.php';
        require_once BKP_CORE_DIR . 'includes/render.php';
        require_once BKP_CORE_DIR . 'includes/public-api.php';

        update_option('bkp_core_version', self::VERSION, false);
    }

    public static function legacy_theme_notice() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-warning"><p><strong>Billekletsers Jaarplanner is geïnstalleerd maar wacht nog.</strong> Activeer nu het thema <em>Billekletsers Planning UI</em>. De oude themaversie bevat dezelfde functies en blijft tot de overstap de bestaande website verzorgen.</p></div>';
    }
}

register_activation_hook(__FILE__, array('BKP_Core_Bootstrap', 'activate'));
BKP_Core_Bootstrap::init();
