<?php
/**
 * Plugin Name: Billekletsers App
 * Plugin URI: https://www.cvdebillekletsers.nl/
 * Description: Maakt de interne Billekletsers Jaarplanner installeerbaar als telefoonapp en regelt duidelijke app-updates zonder opnieuw installeren.
 * Version: 1.2.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: C.V. De Billekletsers
 * Text Domain: billekletsers-app
 */

if (!defined('ABSPATH')) { exit; }

final class BKPA_App {
    const VERSION = '1.2.1';

    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'serve_endpoints'), -100);
        add_action('wp_head', array(__CLASS__, 'head_tags'), 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 30);
        add_action('wp_footer', array(__CLASS__, 'render_install_ui'), 5);
        add_action('bkp_account_menu_items', array(__CLASS__, 'account_menu_item'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 30);
        add_action('admin_notices', array(__CLASS__, 'https_notice'));
        add_action('init', array(__CLASS__, 'record_version'));
    }

    public static function activate() {
        update_option('bkpa_version', self::VERSION, false);
        // Bewust geen import, synchronisatie of wijziging van bestaande jaarplannerdata.
    }

    public static function record_version() {
        if ((string) get_option('bkpa_version', '') !== self::VERSION) {
            update_option('bkpa_version', self::VERSION, false);
        }
    }

    public static function plugin_url($path = '') {
        return plugin_dir_url(__FILE__) . ltrim($path, '/');
    }

    public static function home_scope_path() {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') { return '/'; }
        return trailingslashit($path);
    }

    public static function manifest_url() {
        return add_query_arg('billekletsers_app_manifest', '1', home_url('/'));
    }

    public static function worker_url() {
        return add_query_arg(array(
            'billekletsers_app_worker' => '1',
            'v' => self::VERSION,
        ), home_url('/'));
    }

    public static function serve_endpoints() {
        if (isset($_GET['billekletsers_app_manifest'])) {
            self::serve_manifest();
        }
        if (isset($_GET['billekletsers_app_worker'])) {
            self::serve_worker();
        }
    }

    public static function serve_manifest() {
        $start_url = add_query_arg('app', '1', home_url('/'));
        $manifest = array(
            'id' => home_url('/'),
            'name' => 'Billekletsers Jaarplanner',
            'short_name' => 'Jaarplanner',
            'description' => 'Interne jaarplanning van C.V. De Billekletsers.',
            'lang' => 'nl-NL',
            'start_url' => $start_url,
            'scope' => home_url('/'),
            'display' => 'standalone',
            'display_override' => array('window-controls-overlay', 'standalone', 'minimal-ui'),
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#e30613',
            'icons' => array(
                array(
                    'src' => self::plugin_url('assets/icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src' => self::plugin_url('assets/icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src' => self::plugin_url('assets/icons/icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ),
            ),
            'shortcuts' => array(
                array(
                    'name' => 'Mijn verantwoordelijkheden',
                    'short_name' => 'Mijn werk',
                    'url' => home_url('/#responsibilities'),
                    'icons' => array(array(
                        'src' => self::plugin_url('assets/icons/icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                    )),
                ),
                array(
                    'name' => 'Jaarplanning',
                    'short_name' => 'Planning',
                    'url' => home_url('/#agenda'),
                    'icons' => array(array(
                        'src' => self::plugin_url('assets/icons/icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                    )),
                ),
            ),
        );

        status_header(200);
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function serve_worker() {
        $scope = self::home_scope_path();
        status_header(200);
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . $scope);
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        ?>
const BKPA_VERSION = <?php echo wp_json_encode(self::VERSION); ?>;
self.addEventListener('install', () => {
  /*
   * Een nieuwe versie wacht bewust op akkoord van de gebruiker. Daardoor kan
   * de pagina eerst de melding 'Nieuwe versie beschikbaar' tonen en pas na
   * 'Nu bijwerken' veilig herladen.
   */
});
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('message', (event) => {
  if (!event.data || typeof event.data !== 'object') return;
  if (event.data.type === 'BKPA_SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data.type === 'BKPA_GET_VERSION' && event.source && event.source.postMessage) {
    event.source.postMessage({type: 'BKPA_VERSION', version: BKPA_VERSION});
  }
});
/*
 * Privacybewuste worker: de afgeschermde planning wordt niet offline
 * opgeslagen. Alle pagina- en dataverzoeken blijven rechtstreeks via
 * WordPress lopen, zodat het gedeelde wachtwoord en persoonlijke login
 * leidend blijven.
 */
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  event.respondWith(fetch(event.request));
});
        <?php
        /**
         * Uitbreidingspunt voor losse modules, zoals de pushmeldingen-plugin.
         * De actie mag uitsluitend geldige service-worker-JavaScript uitvoeren.
         */
        do_action('bkpa_service_worker_script');
        exit;
    }

    public static function head_tags() {
        if (is_admin()) { return; }
        echo '<link rel="manifest" href="' . esc_url(self::manifest_url()) . '">' . "\n";
        echo '<meta name="theme-color" content="#e30613">' . "\n";
        echo '<meta name="application-name" content="Billekletsers Jaarplanner">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
        echo '<meta name="apple-mobile-web-app-title" content="Jaarplanner">' . "\n";
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url(self::plugin_url('assets/icons/apple-touch-icon.png')) . '">' . "\n";
    }

    public static function enqueue_assets() {
        if (is_admin()) { return; }
        wp_enqueue_style(
            'billekletsers-app',
            self::plugin_url('assets/css/app.css'),
            array(),
            self::VERSION
        );
        wp_enqueue_script(
            'billekletsers-app',
            self::plugin_url('assets/js/app.js'),
            array(),
            self::VERSION,
            true
        );
        wp_localize_script('billekletsers-app', 'BKPA_CONFIG', array(
            'workerUrl' => self::worker_url(),
            'scopePath' => self::home_scope_path(),
            'homeUrl' => home_url('/'),
            'version' => self::VERSION,
            'labels' => array(
                'installed' => 'De Jaarplanner staat al op dit toestel.',
                'installing' => 'Installeren…',
                'notAvailable' => 'Open deze pagina in Safari of Chrome op je telefoon en probeer opnieuw.',
                'checking' => 'Controleren op updates…',
                'latest' => 'Je gebruikt de nieuwste versie.',
                'available' => 'Er staat een nieuwe versie klaar.',
                'activating' => 'Nieuwe versie activeren…',
                'failed' => 'Controleren is niet gelukt. Probeer het later opnieuw.',
            ),
        ));
    }

    public static function account_menu_item() {
        echo '<a href="#bkpa-install-app" class="bkpa-menu-install-link">App installeren</a>';
        echo '<a href="#bkpa-app-updates" id="bkpa-account-update-link">App &amp; updates</a>';
    }

    public static function render_install_ui() {
        if (is_admin()) { return; }
        ?>
        <button type="button" id="bkpa-install-button" class="bkp-btn bkpa-install-button" hidden aria-haspopup="dialog">
            <span class="bkpa-install-icon" aria-hidden="true">↓</span>
            <span>Installeer op telefoon</span>
        </button>
        <div id="bkpa-install-dialog" class="bkpa-dialog" hidden role="dialog" aria-modal="true" aria-labelledby="bkpa-dialog-title">
            <div class="bkpa-dialog-backdrop" data-bkpa-install-close></div>
            <section class="bkpa-dialog-card" role="document">
                <button class="bkpa-dialog-close" type="button" aria-label="Sluiten" data-bkpa-install-close>×</button>
                <img src="<?php echo esc_url(self::plugin_url('assets/icons/icon-192.png')); ?>" width="72" height="72" alt="">
                <h2 id="bkpa-dialog-title">Installeer de Jaarplanner</h2>
                <div id="bkpa-install-instructions"></div>
                <button type="button" class="bkp-btn bkpa-dialog-done" data-bkpa-install-close>Begrepen</button>
            </section>
        </div>

        <aside id="bkpa-update-notice" class="bkpa-update-notice" hidden aria-live="polite">
            <div>
                <strong>Nieuwe versie beschikbaar</strong>
                <span>Werk de Jaarplanner bij zonder opnieuw te installeren.</span>
            </div>
            <div class="bkpa-update-notice-actions">
                <button type="button" class="bkp-btn" id="bkpa-update-now">Nu bijwerken</button>
                <button type="button" class="bkpa-update-later" id="bkpa-update-later">Later</button>
            </div>
        </aside>

        <div id="bkpa-update-dialog" class="bkpa-dialog" hidden role="dialog" aria-modal="true" aria-labelledby="bkpa-update-dialog-title">
            <div class="bkpa-dialog-backdrop" data-bkpa-update-close></div>
            <section class="bkpa-dialog-card bkpa-update-card" role="document">
                <button class="bkpa-dialog-close" type="button" aria-label="Sluiten" data-bkpa-update-close>×</button>
                <img src="<?php echo esc_url(self::plugin_url('assets/icons/icon-192.png')); ?>" width="72" height="72" alt="">
                <h2 id="bkpa-update-dialog-title">App &amp; updates</h2>
                <dl class="bkpa-version-list">
                    <div><dt>Huidige appversie</dt><dd><?php echo esc_html(self::VERSION); ?></dd></div>
                    <div><dt>Laatste controle</dt><dd id="bkpa-last-check">Nog niet gecontroleerd</dd></div>
                </dl>
                <p id="bkpa-update-status" class="bkpa-update-status" aria-live="polite">De app controleert automatisch op nieuwe versies.</p>
                <div class="bkpa-update-dialog-actions">
                    <button type="button" class="bkp-btn" id="bkpa-check-update">Controleren op updates</button>
                    <button type="button" class="bkp-btn" id="bkpa-dialog-update-now" hidden>Nu bijwerken</button>
                    <button type="button" class="bkp-btn bkp-btn--light" data-bkpa-update-close>Sluiten</button>
                </div>
                <p class="bkpa-update-help">Opnieuw installeren is niet nodig. Na het bijwerken wordt de Jaarplanner één keer herladen; je account en pushkoppeling blijven behouden.</p>
            </section>
        </div>
        <div id="bkpa-install-status" class="screen-reader-text" aria-live="polite"></div>
        <?php
    }

    public static function admin_menu() {
        $parent = function_exists('bkp_render_planner_app') ? 'bkp-planning' : 'options-general.php';
        add_submenu_page(
            $parent,
            'Telefoonapp',
            'Telefoonapp',
            'manage_options',
            'billekletsers-app',
            array(__CLASS__, 'admin_page')
        );
    }

    public static function https_notice() {
        if (!current_user_can('manage_options') || is_ssl()) { return; }
        echo '<div class="notice notice-warning"><p><strong>Billekletsers App:</strong> installeren op een telefoon werkt pas wanneer de planningswebsite via HTTPS wordt geopend.</p></div>';
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) { return; }
        ?>
        <div class="wrap">
            <h1>Billekletsers telefoonapp</h1>
            <p>De Jaarplanner is ingesteld als installeerbare webapp voor Apple- en Android-telefoons.</p>
            <table class="widefat striped" style="max-width:850px">
                <tbody>
                    <tr><th style="width:240px">Pluginversie</th><td><strong><?php echo esc_html(self::VERSION); ?></strong></td></tr>
                    <tr><th>Installeren</th><td><strong>App installeren</strong> staat ook onder <strong>Account</strong> of <strong>Opties</strong>. Op een geschikte Android-browser opent de native installatie; op iPhone verschijnen de stappen voor <strong>Zet op beginscherm</strong>.</td></tr>
                    <tr><th>App-updates</th><td>Een geïnstalleerde app toont automatisch <strong>Nieuwe versie beschikbaar</strong>. De gebruiker kiest daarna <strong>Nu bijwerken</strong>; opnieuw installeren is niet nodig.</td></tr>
                    <tr><th>Handmatig controleren</th><td>Ingelogde leden vinden dit onder <strong>Account → App &amp; updates</strong>.</td></tr>
                    <tr><th>Na installatie</th><td>De installatieknop verdwijnt automatisch en de Jaarplanner opent vanaf het beginscherm als app.</td></tr>
                    <tr><th>Beveiliging</th><td>De planningdata wordt niet offline opgeslagen. Het gedeelde wachtwoord en persoonlijke WordPress-account blijven leidend.</td></tr>
                    <tr><th>Websiteadres</th><td><code><?php echo esc_html(home_url('/')); ?></code></td></tr>
                    <tr><th>HTTPS</th><td><?php echo is_ssl() ? '<span style="color:#137333;font-weight:700">Actief</span>' : '<span style="color:#b32d2e;font-weight:700">Nog niet actief</span>'; ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:18px"><a class="button button-primary" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">Jaarplanner openen</a></p>
            <p><em>Deze plugin importeert, synchroniseert of wijzigt geen bestaande jaarplannerdata.</em></p>
        </div>
        <?php
    }
}

BKPA_App::init();
register_activation_hook(__FILE__, array('BKPA_App', 'activate'));
