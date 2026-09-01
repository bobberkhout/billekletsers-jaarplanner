<?php
if (!defined('ABSPATH')) { exit; }

final class BKPP_Plugin {
    const TABLE_SCHEMA = '1.0.0';
    const OPTION_SETTINGS = 'bkpp_settings';
    const OPTION_LOG = 'bkpp_log';
    const OPTION_LAST_RUN = 'bkpp_last_run';
    const OPTION_VAPID_PUBLIC = 'bkpp_vapid_public';
    const OPTION_VAPID_PRIVATE_PEM = 'bkpp_vapid_private_pem';
    const CRON_HOOK = 'bkpp_daily_check';
    const PAGE_SLUG = 'bkpp-push';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'), 100);
        add_action('admin_init', array($this, 'ensure_schedule'));
        add_action('admin_notices', array($this, 'dependency_notices'));
        add_action(self::CRON_HOOK, array($this, 'cron_run'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend'));
        add_action('wp_footer', array($this, 'render_frontend_ui'), 5);
        add_action('bkpa_service_worker_script', array($this, 'service_worker_script'));
        add_action('bkp_account_menu_items', array($this, 'account_menu_item'));

        add_action('wp_ajax_bkpp_subscribe', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_bkpp_unsubscribe', array($this, 'ajax_unsubscribe'));
        add_action('wp_ajax_bkpp_save_preference', array($this, 'ajax_save_preference'));
        add_action('wp_ajax_bkpp_test_push', array($this, 'ajax_test_push'));
        add_action('wp_ajax_bkpp_pending', array($this, 'ajax_pending'));
        add_action('wp_ajax_nopriv_bkpp_pending', array($this, 'ajax_pending'));

        add_action('admin_post_bkpp_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_bkpp_run_now', array($this, 'handle_run_now'));
        add_action('admin_post_bkpp_test_admin', array($this, 'handle_test_admin'));
        add_action('admin_post_bkpp_clear_log', array($this, 'handle_clear_log'));

        add_action('bkp_after_season_restore', array($this, 'after_season_restore'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bkpp_push_subscriptions';
    }

    public static function default_settings() {
        return array(
            'enabled' => '0',
            'send_time' => '08:05',
            'lead_days' => '14,7,3,1,0',
            'include_events' => '1',
            'include_main_projects' => '1',
            'include_projects' => '1',
            'include_todos' => '1',
            'include_inventories' => '1',
            'include_actions' => '1',
            'skip_completed' => '1',
        );
    }

    public static function activate() {
        self::install_table();
        self::ensure_vapid_keys();
        $settings = get_option(self::OPTION_SETTINGS, array());
        update_option(self::OPTION_SETTINGS, wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings()), false);
        if (false === get_option(self::OPTION_LOG, false)) add_option(self::OPTION_LOG, array(), '', false);
        self::clear_schedule();
        self::schedule_next_static();
    }

    public static function deactivate() {
        self::clear_schedule();
    }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            endpoint_hash char(64) NOT NULL,
            endpoint text NOT NULL,
            p256dh text NOT NULL,
            auth_token text NOT NULL,
            content_encoding varchar(32) NOT NULL DEFAULT 'aes128gcm',
            user_agent text NOT NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            pending_payload longtext NULL,
            pending_at datetime NULL,
            last_seen datetime NULL,
            last_sent datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_active (user_id, active)
        ) {$charset};";
        dbDelta($sql);
        update_option('bkpp_table_schema', self::TABLE_SCHEMA, false);
    }

    private static function ensure_vapid_keys() {
        $public = (string) get_option(self::OPTION_VAPID_PUBLIC, '');
        $private = (string) get_option(self::OPTION_VAPID_PRIVATE_PEM, '');
        if ($public !== '' && $private !== '') return true;
        if (!function_exists('openssl_pkey_new') || !defined('OPENSSL_KEYTYPE_EC')) return false;
        $key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
        if (!$key) return false;
        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) return false;
        $pem = '';
        if (!openssl_pkey_export($key, $pem) || $pem === '') return false;
        $public = self::base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);
        update_option(self::OPTION_VAPID_PUBLIC, $public, false);
        update_option(self::OPTION_VAPID_PRIVATE_PEM, $pem, false);
        return true;
    }

    private static function settings() {
        $stored = get_option(self::OPTION_SETTINGS, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), self::default_settings());
    }

    private static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function next_timestamp($time_string) {
        $time_string = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $time_string) ? $time_string : '08:05';
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $candidate = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time_string . ':00', $timezone);
        if ($candidate <= $now) $candidate = $candidate->modify('+1 day');
        return $candidate->getTimestamp();
    }

    private static function schedule_next_static() {
        $settings = self::settings();
        if (empty($settings['enabled'])) return;
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(self::next_timestamp($settings['send_time']), self::CRON_HOOK);
        }
    }

    public function ensure_schedule() {
        if (version_compare((string) get_option('bkpp_table_schema', '0'), self::TABLE_SCHEMA, '<')) self::install_table();
        self::ensure_vapid_keys();
        $settings = self::settings();
        if (!empty($settings['enabled'])) self::schedule_next_static();
        elseif (wp_next_scheduled(self::CRON_HOOK)) self::clear_schedule();
    }

    public function cron_run() {
        $settings = self::settings();
        if (!empty($settings['enabled'])) $this->process_due(false);
        self::schedule_next_static();
    }

    public function admin_menu() {
        $parent = post_type_exists('bkp_event') ? 'bkp-planning' : 'options-general.php';
        add_submenu_page($parent, 'Pushmeldingen', 'Pushmeldingen', 'manage_options', self::PAGE_SLUG, array($this, 'admin_page'));
    }

    public function dependency_notices() {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && false === strpos((string) $screen->id, self::PAGE_SLUG) && false === strpos((string) $screen->id, 'plugins')) return;
        if (!class_exists('BKPA_App') || !defined('BKPA_App::VERSION') || version_compare(BKPA_App::VERSION, '1.1.0', '<')) {
            echo '<div class="notice notice-warning"><p><strong>Billekletsers Pushmeldingen:</strong> installeer of update eerst <em>Billekletsers App 1.1.0</em>. Die versie bevat de service-workerkoppeling voor pushmeldingen.</p></div>';
        }
        if (!is_ssl()) echo '<div class="notice notice-error"><p><strong>Billekletsers Pushmeldingen:</strong> pushmeldingen werken alleen wanneer de website via HTTPS wordt geopend.</p></div>';
        if (!function_exists('openssl_sign') || !defined('OPENSSL_KEYTYPE_EC')) echo '<div class="notice notice-error"><p><strong>Billekletsers Pushmeldingen:</strong> de hosting mist vereiste OpenSSL-ondersteuning voor P-256 sleutels.</p></div>';
    }

    public function enqueue_frontend() {
        if (!is_user_logged_in() || is_admin()) return;
        wp_enqueue_style('bkpp-push', BKPP_URL . 'assets/push.css', array(), BKPP_VERSION);
        wp_enqueue_script('bkpp-push', BKPP_URL . 'assets/push.js', array(), BKPP_VERSION, true);
        $user_id = get_current_user_id();
        $preference = sanitize_key((string) get_user_meta($user_id, 'bkpp_notification_preference', true));
        if (!in_array($preference, array('both', 'email', 'push', 'none'), true)) $preference = 'both';
        wp_localize_script('bkpp-push', 'BKPP_CONFIG', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bkpp_front'),
            'publicKey' => (string) get_option(self::OPTION_VAPID_PUBLIC, ''),
            'preference' => $preference,
            'settingsUrl' => add_query_arg('bkpp_open', '1', home_url('/')) . '#bkpp-meldingen',
            'appInstalledHint' => 'Op iPhone moet de Jaarplanner eerst via Safari op het beginscherm zijn gezet.',
        ));
    }

    public function account_menu_item() {
        if (!is_user_logged_in()) return;
        $url = add_query_arg('bkpp_open', '1', home_url('/')) . '#bkpp-meldingen';
        echo '<a href="' . esc_url($url) . '" data-bkpp-menu="1">Meldingen instellen</a>';
    }

    public function render_frontend_ui() {
        if (!is_user_logged_in() || is_admin()) return;
        $open_on_load = isset($_GET['bkpp_open']) && '1' === sanitize_text_field(wp_unslash($_GET['bkpp_open']));
        ?>
        <button type="button" id="bkpp-open-settings" class="bkpp-hidden-trigger" hidden>Meldingen instellen</button>
        <div id="bkpp-dialog" class="bkpp-dialog"<?php echo $open_on_load ? '' : ' hidden'; ?> role="dialog" aria-modal="true" aria-labelledby="bkpp-dialog-title">
            <div class="bkpp-backdrop" data-bkpp-close></div>
            <section class="bkpp-card" role="document">
                <button type="button" class="bkpp-close" aria-label="Sluiten" data-bkpp-close>×</button>
                <img src="<?php echo esc_url(BKPP_URL . 'assets/icon-192.png'); ?>" width="64" height="64" alt="">
                <h2 id="bkpp-dialog-title">Meldingen op je telefoon</h2>
                <p id="bkpp-support-message" class="bkpp-support-message"></p>
                <fieldset class="bkpp-preferences">
                    <legend>Hoe wil je herinneringen ontvangen?</legend>
                    <label><input type="radio" name="bkpp_preference" value="both"> E-mail én pushmelding</label>
                    <label><input type="radio" name="bkpp_preference" value="email"> Alleen e-mail</label>
                    <label><input type="radio" name="bkpp_preference" value="push"> Alleen pushmelding</label>
                    <label><input type="radio" name="bkpp_preference" value="none"> Geen persoonlijke herinneringen</label>
                </fieldset>
                <div class="bkpp-actions">
                    <button type="button" class="bkp-btn" id="bkpp-enable">Pushmeldingen aanzetten</button>
                    <button type="button" class="bkp-btn bkp-btn--light" id="bkpp-test">Testmelding sturen</button>
                    <button type="button" class="bkp-btn bkp-btn--light" id="bkpp-disable">Pushmeldingen uitzetten</button>
                </div>
                <div id="bkpp-status" class="bkpp-status" aria-live="polite"></div>
            </section>
        </div>
        <?php
    }

    public function service_worker_script() {
        $ajax = admin_url('admin-ajax.php');
        $icon = BKPP_URL . 'assets/icon-192.png';
        $home = home_url('/#responsibilities');
        ?>

/* Billekletsers Pushmeldingen <?php echo esc_js(BKPP_VERSION); ?> */
const BKPP_PUSH_AJAX = <?php echo wp_json_encode($ajax); ?>;
const BKPP_PUSH_ICON = <?php echo wp_json_encode($icon); ?>;
const BKPP_PUSH_HOME = <?php echo wp_json_encode($home); ?>;
self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let payload = {title: 'Billekletsers Jaarplanner', body: 'Je hebt een nieuwe herinnering.', url: BKPP_PUSH_HOME, tag: 'billekletsers-herinnering'};
    try {
      const subscription = await self.registration.pushManager.getSubscription();
      if (subscription && subscription.endpoint) {
        const form = new URLSearchParams();
        form.set('action', 'bkpp_pending');
        form.set('endpoint', subscription.endpoint);
        const response = await fetch(BKPP_PUSH_AJAX, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
          body: form.toString(),
          credentials: 'omit',
          cache: 'no-store'
        });
        const json = await response.json();
        if (json && json.success && json.data) payload = Object.assign(payload, json.data);
      }
    } catch (error) {
      // De generieke melding blijft beschikbaar wanneer het ophalen tijdelijk mislukt.
    }
    await self.registration.showNotification(payload.title || 'Billekletsers Jaarplanner', {
      body: payload.body || 'Je hebt een nieuwe herinnering.',
      icon: BKPP_PUSH_ICON,
      badge: BKPP_PUSH_ICON,
      tag: payload.tag || 'billekletsers-herinnering',
      renotify: false,
      data: {url: payload.url || BKPP_PUSH_HOME}
    });
  })());
});
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || BKPP_PUSH_HOME;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
    for (const client of windows) {
      if ('focus' in client) {
        try { await client.navigate(target); } catch (error) {}
        return client.focus();
      }
    }
    if (self.clients.openWindow) return self.clients.openWindow(target);
  })());
});
        <?php
    }

    private function require_front_nonce() {
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Log eerst in met je persoonlijke account.'), 401);
        check_ajax_referer('bkpp_front', 'nonce');
    }

    public function ajax_subscribe() {
        $this->require_front_nonce();
        $raw = isset($_POST['subscription']) ? wp_unslash($_POST['subscription']) : '';
        $subscription = json_decode((string) $raw, true);
        if (!is_array($subscription)) wp_send_json_error(array('message' => 'De telefoon stuurde geen geldige inschrijving.'), 400);
        $endpoint = esc_url_raw((string) ($subscription['endpoint'] ?? ''));
        $p256dh = sanitize_text_field((string) ($subscription['keys']['p256dh'] ?? ''));
        $auth = sanitize_text_field((string) ($subscription['keys']['auth'] ?? ''));
        if (!$this->valid_endpoint($endpoint)) wp_send_json_error(array('message' => 'Het pushadres is ongeldig.'), 400);
        global $wpdb;
        $table = self::table_name();
        $now = current_time('mysql');
        $hash = hash('sha256', $endpoint);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE endpoint_hash = %s", $hash));
        $data = array(
            'user_id' => get_current_user_id(),
            'endpoint_hash' => $hash,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth_token' => $auth,
            'content_encoding' => 'aes128gcm',
            'user_agent' => sanitize_text_field(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)),
            'active' => 1,
            'last_seen' => $now,
            'updated_at' => $now,
        );
        if ($existing) $ok = $wpdb->update($table, $data, array('id' => absint($existing)));
        else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($table, $data);
        }
        if (false === $ok) wp_send_json_error(array('message' => 'De telefoon kon niet worden gekoppeld.'), 500);
        wp_send_json_success(array('message' => 'Pushmeldingen zijn op dit toestel aangezet.'));
    }

    public function ajax_unsubscribe() {
        $this->require_front_nonce();
        $endpoint = esc_url_raw((string) wp_unslash($_POST['endpoint'] ?? ''));
        if ($endpoint !== '') {
            global $wpdb;
            $wpdb->update(self::table_name(), array('active' => 0, 'updated_at' => current_time('mysql')), array('endpoint_hash' => hash('sha256', $endpoint), 'user_id' => get_current_user_id()));
        }
        wp_send_json_success(array('message' => 'Pushmeldingen zijn op dit toestel uitgezet.'));
    }

    public function ajax_save_preference() {
        $this->require_front_nonce();
        $preference = sanitize_key((string) wp_unslash($_POST['preference'] ?? 'both'));
        if (!in_array($preference, array('both', 'email', 'push', 'none'), true)) $preference = 'both';
        update_user_meta(get_current_user_id(), 'bkpp_notification_preference', $preference);
        wp_send_json_success(array('message' => 'Je meldingskeuze is opgeslagen.', 'preference' => $preference));
    }

    public function ajax_test_push() {
        $this->require_front_nonce();
        $result = $this->send_to_user(get_current_user_id(), array(
            'title' => 'Testmelding Jaarplanner',
            'body' => 'Pushmeldingen werken op dit toestel.',
            'url' => home_url('/#responsibilities'),
            'tag' => 'bkpp-test-' . time(),
        ));
        if (empty($result['success'])) wp_send_json_error(array('message' => $result['message']), 400);
        wp_send_json_success(array('message' => $result['message']));
    }

    public function ajax_pending() {
        $endpoint = esc_url_raw((string) wp_unslash($_POST['endpoint'] ?? ''));
        if (!$this->valid_endpoint($endpoint)) wp_send_json_error(array('message' => 'Geen melding beschikbaar.'), 400);
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, pending_payload FROM {$table} WHERE endpoint_hash = %s AND active = 1 LIMIT 1", hash('sha256', $endpoint)));
        if (!$row) wp_send_json_error(array('message' => 'Geen melding beschikbaar.'), 404);
        $payload = json_decode((string) $row->pending_payload, true);
        if (!is_array($payload)) $payload = array('title' => 'Billekletsers Jaarplanner', 'body' => 'Je hebt een nieuwe herinnering.', 'url' => home_url('/#responsibilities'));
        $payload['url'] = $this->safe_notification_url($payload['url'] ?? '');
        $wpdb->update($table, array('pending_payload' => null, 'pending_at' => null, 'last_seen' => current_time('mysql')), array('id' => absint($row->id)));
        wp_send_json_success($payload);
    }

    private function valid_endpoint($endpoint) {
        if (!is_string($endpoint) || strlen($endpoint) < 20 || strlen($endpoint) > 4096) return false;
        $parts = wp_parse_url($endpoint);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
    }

    private function safe_notification_url($url) {
        $url = esc_url_raw((string) $url);
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($url === '' || $home_host === '' || $url_host !== $home_host) return home_url('/#responsibilities');
        return $url;
    }

    private function user_allows_push($user_id) {
        $preference = sanitize_key((string) get_user_meta($user_id, 'bkpp_notification_preference', true));
        if ($preference === '') $preference = 'both';
        return in_array($preference, array('both', 'push'), true);
    }

    private function active_subscriptions($user_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE user_id = %d AND active = 1 ORDER BY updated_at DESC", absint($user_id)));
    }

    private function send_to_user($user_id, $payload) {
        $subscriptions = $this->active_subscriptions($user_id);
        if (!$subscriptions) return array('success' => false, 'message' => 'Er is nog geen telefoon gekoppeld. Zet pushmeldingen eerst aan in de geïnstalleerde app.');
        global $wpdb;
        $success = 0;
        $errors = 0;
        foreach ($subscriptions as $subscription) {
            $wpdb->update(self::table_name(), array(
                'pending_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pending_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ), array('id' => absint($subscription->id)));
            $sent = $this->send_empty_push($subscription->endpoint);
            if (!empty($sent['success'])) {
                $success++;
                $wpdb->update(self::table_name(), array('last_sent' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => absint($subscription->id)));
            } else {
                $errors++;
                if (!empty($sent['expired'])) $wpdb->update(self::table_name(), array('active' => 0, 'updated_at' => current_time('mysql')), array('id' => absint($subscription->id)));
                $this->add_log('error', 'Push naar toestel mislukt: ' . $sent['message'], (string) $user_id);
            }
        }
        if ($success) return array('success' => true, 'message' => $success . ' toestel(len) hebben de melding ontvangen.', 'count' => $success);
        return array('success' => false, 'message' => 'De testmelding kon niet worden verzonden. Controleer HTTPS, de app-installatie en de hosting.', 'errors' => $errors);
    }

    private function send_empty_push($endpoint) {
        if (!self::ensure_vapid_keys()) return array('success' => false, 'expired' => false, 'message' => 'VAPID-sleutels konden niet worden aangemaakt.');
        $public = (string) get_option(self::OPTION_VAPID_PUBLIC, '');
        $private = (string) get_option(self::OPTION_VAPID_PRIVATE_PEM, '');
        $audience = $this->endpoint_origin($endpoint);
        if ($audience === '') return array('success' => false, 'expired' => true, 'message' => 'Ongeldig pushadres.');
        $jwt = $this->vapid_jwt($audience, $private);
        if (is_wp_error($jwt)) return array('success' => false, 'expired' => false, 'message' => $jwt->get_error_message());
        $response = wp_remote_post($endpoint, array(
            'timeout' => 18,
            'redirection' => 0,
            'body' => '',
            'headers' => array(
                'TTL' => '86400',
                'Urgency' => 'normal',
                'Authorization' => 'vapid t=' . $jwt . ', k=' . $public,
                'Content-Length' => '0',
            ),
            'user-agent' => 'Billekletsers-Push/' . BKPP_VERSION . '; ' . home_url('/'),
        ));
        if (is_wp_error($response)) return array('success' => false, 'expired' => false, 'message' => $response->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($response);
        if (in_array($code, array(200, 201, 202), true)) return array('success' => true, 'expired' => false, 'message' => 'Verzonden');
        $expired = in_array($code, array(404, 410), true);
        return array('success' => false, 'expired' => $expired, 'message' => 'Pushdienst antwoordde met HTTP ' . $code . '.');
    }

    private function endpoint_origin($endpoint) {
        $parts = wp_parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) $origin .= ':' . absint($parts['port']);
        return $origin;
    }

    private function vapid_jwt($audience, $private_pem) {
        $header = self::base64url_encode(wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
        $subject = sanitize_email((string) get_option('admin_email'));
        $subject = $subject ? 'mailto:' . $subject : home_url('/');
        $payload = self::base64url_encode(wp_json_encode(array('aud' => $audience, 'exp' => time() + 43200, 'sub' => $subject)));
        $input = $header . '.' . $payload;
        $der = '';
        if (!openssl_sign($input, $der, $private_pem, OPENSSL_ALGO_SHA256)) return new WP_Error('vapid_sign', 'De VAPID-handtekening kon niet worden gemaakt.');
        $raw = $this->der_signature_to_raw($der, 32);
        if ($raw === false) return new WP_Error('vapid_signature', 'De VAPID-handtekening heeft een onbekend formaat.');
        return $input . '.' . self::base64url_encode($raw);
    }

    private function der_signature_to_raw($der, $part_length) {
        $offset = 0;
        if ($this->read_byte($der, $offset) !== 0x30) return false;
        $sequence_length = $this->read_asn1_length($der, $offset);
        if ($sequence_length === false || $this->read_byte($der, $offset) !== 0x02) return false;
        $r_length = $this->read_asn1_length($der, $offset);
        if ($r_length === false || $offset + $r_length > strlen($der)) return false;
        $r = substr($der, $offset, $r_length); $offset += $r_length;
        if ($this->read_byte($der, $offset) !== 0x02) return false;
        $s_length = $this->read_asn1_length($der, $offset);
        if ($s_length === false || $offset + $s_length > strlen($der)) return false;
        $s = substr($der, $offset, $s_length);
        $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
        if (strlen($r) > $part_length || strlen($s) > $part_length) return false;
        return str_pad($r, $part_length, "\x00", STR_PAD_LEFT) . str_pad($s, $part_length, "\x00", STR_PAD_LEFT);
    }

    private function read_byte($data, &$offset) {
        if ($offset >= strlen($data)) return false;
        return ord($data[$offset++]);
    }

    private function read_asn1_length($data, &$offset) {
        $length = $this->read_byte($data, $offset);
        if ($length === false) return false;
        if (($length & 0x80) === 0) return $length;
        $octets = $length & 0x7f;
        if ($octets < 1 || $octets > 4 || $offset + $octets > strlen($data)) return false;
        $length = 0;
        for ($i = 0; $i < $octets; $i++) $length = ($length << 8) | $this->read_byte($data, $offset);
        return $length;
    }

    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    private function parse_days($value) {
        $parts = preg_split('/[^0-9-]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $days = array();
        foreach ((array) $parts as $part) {
            $day = intval($part);
            if ($day < -30 || $day > 365 || in_array($day, $days, true)) continue;
            $days[] = $day;
        }
        rsort($days, SORT_NUMERIC);
        return $days ?: array(14, 7, 3, 1, 0);
    }

    private function item_from_post($post) {
        if (!$post instanceof WP_Post) return null;
        $type = '';
        $label = '';
        $source = '';
        $status = '';
        if ($post->post_type === 'bkp_project') {
            $type = 'main_project'; $label = 'Project'; $source = (string) get_post_meta($post->ID, '_bkp_project_end', true);
            $status = (string) get_post_meta($post->ID, '_bkp_project_status', true);
        } elseif ($post->post_type === 'bkp_event') {
            $type = 'event'; $label = 'Jaarplanning'; $source = (string) get_post_meta($post->ID, '_bkp_date', true);
            $status = get_post_meta($post->ID, '_bkp_confirmed', true) === '1' ? 'Vastgesteld' : 'Concept';
        } elseif ($post->post_type === 'bkp_inventory') {
            $type = 'inventory'; $label = 'Inventarisatie'; $source = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
            if ((string) get_post_meta($post->ID, '_bkp_inventory_open', true) !== '1') $status = 'Gesloten';
        } elseif ($post->post_type === 'bkp_action') {
            $type = 'action'; $label = 'Actie/besluit'; $source = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            $status = (string) get_post_meta($post->ID, '_bkp_done', true);
        } elseif ($post->post_type === 'bkp_task') {
            $category = (string) get_post_meta($post->ID, '_bkp_category', true);
            if ($category === 'To-do') { $type = 'todo'; $label = 'To-do'; $source = (string) get_post_meta($post->ID, '_bkp_due_date', true); }
            elseif ($category === 'Projectplanning') { $type = 'project'; $label = 'Projectplanning'; $source = (string) get_post_meta($post->ID, '_bkp_due_date', true); }
            else return null;
            $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
        } else return null;
        $custom = $this->valid_date((string) get_post_meta($post->ID, '_bkpr_deadline', true));
        $source = $this->valid_date($source);
        $enabled_meta = (string) get_post_meta($post->ID, '_bkpr_enabled', true);
        $ids = get_post_meta($post->ID, '_bkp_assigned_user_ids', true);
        $ids = is_array($ids) ? array_map('absint', $ids) : array_map('absint', preg_split('/[^0-9]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY));
        return array(
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'project_id' => $post->post_type === 'bkp_project' ? (int) $post->ID : absint(get_post_meta($post->ID, '_bkp_project_id', true)),
            'project_name' => $post->post_type === 'bkp_project' ? $post->post_title : (function_exists('bkp_project_name') ? bkp_project_name(absint(get_post_meta($post->ID, '_bkp_project_id', true)), '') : ''),
            'type' => $type,
            'label' => $label,
            'deadline' => $custom ?: $source,
            'status' => $status,
            'user_ids' => array_values(array_unique(array_filter($ids))),
            'lead_days' => (string) get_post_meta($post->ID, '_bkpr_lead_days', true),
            'enabled' => $enabled_meta === '' || $enabled_meta === '1',
        );
    }

    private function valid_date($date) {
        $date = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $d && $d->format('Y-m-d') === $date ? $date : '';
    }

    private function include_type($type, $settings) {
        $map = array('main_project' => 'include_main_projects', 'event' => 'include_events', 'project' => 'include_projects', 'todo' => 'include_todos', 'inventory' => 'include_inventories', 'action' => 'include_actions');
        return !empty($map[$type]) && !empty($settings[$map[$type]]);
    }

    private function is_completed($item) {
        $status = strtolower(remove_accents(trim((string) $item['status'])));
        if ($status === '') return false;
        $status = preg_replace('/[^a-z0-9]+/', ' ', $status);
        $exact = array('ja', 'yes', 'done', 'af', 'klaar', 'gereed', 'afgerond', 'voltooid', 'completed', 'geannuleerd', 'gearchiveerd', 'archief', 'vervallen', 'gesloten');
        if (in_array(trim($status), $exact, true)) return true;
        foreach (array('afgerond', 'voltooid', 'completed', 'geannuleerd', 'gearchiveerd', 'vervallen', 'niet meer nodig') as $word) {
            if (strpos($status, $word) !== false) return true;
        }
        return false;
    }

    private function signature($item, $days, $user_id) {
        return hash('sha256', implode('|', array($item['id'], $item['deadline'], $days, absint($user_id))));
    }

    private function signature_sent($post_id, $signature) {
        $history = get_post_meta($post_id, '_bkpp_sent_signatures', true);
        return is_array($history) && isset($history[$signature]);
    }

    private function mark_signature_sent($post_id, $signature) {
        $history = get_post_meta($post_id, '_bkpp_sent_signatures', true);
        $history = is_array($history) ? $history : array();
        $history[$signature] = time();
        if (count($history) > 120) {
            asort($history, SORT_NUMERIC);
            $history = array_slice($history, -120, null, true);
        }
        update_post_meta($post_id, '_bkpp_sent_signatures', $history);
    }

    private function process_due($manual) {
        $settings = self::settings();
        $result = array('users' => 0, 'devices' => 0, 'items' => 0, 'errors' => 0);
        if (empty($settings['enabled']) && !$manual) return $result;
        $posts = get_posts(array('post_type' => array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action'), 'post_status' => array('publish', 'draft', 'private', 'pending'), 'posts_per_page' => -1));
        $today = new DateTimeImmutable('today', wp_timezone());
        $global_days = $this->parse_days($settings['lead_days']);
        $digests = array();
        foreach ($posts as $post) {
            $item = $this->item_from_post($post);
            if (!$item || !$item['enabled'] || !$item['deadline'] || !$this->include_type($item['type'], $settings)) continue;
            if (!empty($settings['skip_completed']) && $this->is_completed($item)) continue;
            $deadline = DateTimeImmutable::createFromFormat('!Y-m-d', $item['deadline'], wp_timezone());
            if (!$deadline) continue;
            $days = (int) $today->diff($deadline)->format('%r%a');
            $lead_days = trim($item['lead_days']) !== '' ? $this->parse_days($item['lead_days']) : $global_days;
            if (!in_array($days, $lead_days, true)) continue;
            foreach ($item['user_ids'] as $user_id) {
                if (!$this->user_allows_push($user_id) || !$this->active_subscriptions($user_id)) continue;
                $signature = $this->signature($item, $days, $user_id);
                if ($this->signature_sent($item['id'], $signature)) continue;
                if (!isset($digests[$user_id])) $digests[$user_id] = array();
                $digests[$user_id][] = array('item' => $item, 'days' => $days, 'signature' => $signature);
            }
        }
        foreach ($digests as $user_id => $entries) {
            $payload = $this->digest_payload($entries);
            $sent = $this->send_to_user($user_id, $payload);
            if (!empty($sent['success'])) {
                $result['users']++;
                $result['devices'] += (int) ($sent['count'] ?? 0);
                $result['items'] += count($entries);
                foreach ($entries as $entry) $this->mark_signature_sent($entry['item']['id'], $entry['signature']);
                $this->add_log('success', count($entries) . ' herinnering(en) verzonden.', (string) $user_id);
            } else $result['errors']++;
        }
        update_option(self::OPTION_LAST_RUN, array('time' => time(), 'result' => $result), false);
        return $result;
    }

    private function digest_payload($entries) {
        $count = count($entries);
        $first = $entries[0];
        $days = (int) $first['days'];
        if ($count === 1) {
            if ($days === 0) $when = 'deadline vandaag';
            elseif ($days === 1) $when = 'deadline morgen';
            elseif ($days > 1) $when = 'deadline over ' . $days . ' dagen';
            else $when = abs($days) . ' dag(en) te laat';
            $body = (!empty($first['item']['project_name']) && $first['item']['type'] !== 'main_project' ? $first['item']['project_name'] . ': ' : '') . $first['item']['title'] . ' · ' . $when;
            $title = 'Herinnering ' . $first['item']['label'];
        } else {
            $title = $count . ' herinneringen in de Jaarplanner';
            $names = array();
            foreach (array_slice($entries, 0, 3) as $entry) $names[] = (!empty($entry['item']['project_name']) && $entry['item']['type'] !== 'main_project' ? $entry['item']['project_name'] . ': ' : '') . $entry['item']['title'];
            $body = implode(', ', $names) . ($count > 3 ? ' en nog ' . ($count - 3) : '');
        }
        return array('title' => $title, 'body' => $body, 'url' => home_url('/#responsibilities'), 'tag' => 'bkpp-' . wp_date('Y-m-d'));
    }

    private function add_log($level, $message, $recipient) {
        $log = get_option(self::OPTION_LOG, array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, array('time' => time(), 'level' => sanitize_key($level), 'message' => sanitize_text_field($message), 'recipient' => sanitize_text_field($recipient)));
        if (count($log) > 250) $log = array_slice($log, 0, 250);
        update_option(self::OPTION_LOG, $log, false);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        global $wpdb;
        $table = self::table_name();
        $active_devices = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1");
        $active_users = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE active = 1");
        $last = get_option(self::OPTION_LAST_RUN, array());
        $log = get_option(self::OPTION_LOG, array());
        ?>
        <div class="wrap"><h1>Billekletsers Pushmeldingen</h1>
        <?php if (!empty($_GET['bkpp_notice'])): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['bkpp_notice']))); ?></p></div><?php endif; ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">
            <div class="card"><h2><?php echo esc_html($active_devices); ?></h2><p>actieve toestellen</p></div>
            <div class="card"><h2><?php echo esc_html($active_users); ?></h2><p>gebruikers met push</p></div>
            <div class="card"><h2><?php echo !empty($settings['enabled']) ? 'Actief' : 'Uit'; ?></h2><p>dagelijkse controle</p></div>
            <div class="card"><h2><?php echo !empty($last['time']) ? esc_html(wp_date('d-m H:i', (int) $last['time'])) : 'Nog niet'; ?></h2><p>laatste controle</p></div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bkpp_save_settings"><?php wp_nonce_field('bkpp_save_settings'); ?>
            <table class="form-table"><tbody>
                <tr><th>Automatisch verzenden</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> dagelijkse controle inschakelen</label></td></tr>
                <tr><th><label for="bkpp_time">Controle om</label></th><td><input id="bkpp_time" type="time" name="send_time" value="<?php echo esc_attr($settings['send_time']); ?>"> <span class="description">volgens de WordPress-tijdzone</span></td></tr>
                <tr><th><label for="bkpp_days">Dagen vooraf</label></th><td><input id="bkpp_days" class="regular-text" name="lead_days" value="<?php echo esc_attr($settings['lead_days']); ?>"><p class="description">Bijvoorbeeld 14,7,3,1,0. Een eigen instelling bij een planningitem blijft leidend.</p></td></tr>
                <tr><th>Onderdelen</th><td><?php foreach (array('include_events'=>'Jaarplanning','include_main_projects'=>'Projecten','include_projects'=>'Projectplanning','include_todos'=>'To-do','include_inventories'=>'Inventarisaties','include_actions'=>'Acties en besluiten') as $key=>$label): ?><label style="display:block;margin-bottom:5px"><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked(!empty($settings[$key])); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></td></tr>
                <tr><th>Afgeronde onderdelen</th><td><label><input type="checkbox" name="skip_completed" value="1" <?php checked(!empty($settings['skip_completed'])); ?>> geen pushmelding sturen</label></td></tr>
            </tbody></table>
            <?php submit_button('Instellingen opslaan'); ?>
        </form>
        <hr><h2>Controleren en testen</h2><p>Pushmeldingen worden uitsluitend verstuurd naar rechtstreeks gekoppelde WordPress-gebruikers die op hun telefoon toestemming hebben gegeven.</p>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bkpp_run_now'), 'bkpp_run_now')); ?>">Nu op deadlines controleren</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bkpp_test_admin'), 'bkpp_test_admin')); ?>">Test naar mijn account</a></p>
        <h2>Actieve toestellen</h2><?php $this->render_devices(); ?>
        <h2>Verzendlog</h2>
        <?php if (!$log): ?><p>Het log is nog leeg.</p><?php else: ?><table class="widefat striped"><thead><tr><th>Tijd</th><th>Status</th><th>Gebruiker</th><th>Melding</th></tr></thead><tbody><?php foreach (array_slice($log,0,100) as $row): ?><tr><td><?php echo esc_html(wp_date('d-m-Y H:i', (int) $row['time'])); ?></td><td><?php echo esc_html($row['level']); ?></td><td><?php echo esc_html($row['recipient']); ?></td><td><?php echo esc_html($row['message']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bkpp_clear_log'), 'bkpp_clear_log')); ?>">Log wissen</a></p>
        <p><em>Deze plugin importeert, verplaatst of overschrijft geen planningdata.</em></p></div>
        <?php
    }

    private function render_devices() {
        global $wpdb;
        $rows = (array) $wpdb->get_results("SELECT * FROM " . self::table_name() . " WHERE active = 1 ORDER BY updated_at DESC LIMIT 200");
        if (!$rows) { echo '<p>Er zijn nog geen telefoons gekoppeld.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Gebruiker</th><th>Toestel/browser</th><th>Gekoppeld</th><th>Laatst verzonden</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);
            echo '<tr><td>'.esc_html($user ? $user->display_name : ('Gebruiker #' . $row->user_id)).'</td><td>'.esc_html(wp_html_excerpt((string) $row->user_agent, 90, '…')).'</td><td>'.esc_html(mysql2date('d-m-Y H:i', $row->created_at)).'</td><td>'.esc_html($row->last_sent ? mysql2date('d-m-Y H:i', $row->last_sent) : 'Nog niet').'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function redirect_notice($message) {
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'bkpp_notice' => $message), admin_url('admin.php')));
        exit;
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkpp_save_settings');
        $time = sanitize_text_field((string) wp_unslash($_POST['send_time'] ?? '08:05'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '08:05';
        $settings = array('enabled' => !empty($_POST['enabled']) ? '1' : '0', 'send_time' => $time, 'lead_days' => implode(',', $this->parse_days(wp_unslash($_POST['lead_days'] ?? '14,7,3,1,0'))), 'skip_completed' => !empty($_POST['skip_completed']) ? '1' : '0');
        foreach (array('include_events','include_main_projects','include_projects','include_todos','include_inventories','include_actions') as $key) $settings[$key] = !empty($_POST[$key]) ? '1' : '0';
        update_option(self::OPTION_SETTINGS, $settings, false);
        self::clear_schedule(); self::schedule_next_static();
        $this->redirect_notice('De pushinstellingen zijn opgeslagen.');
    }

    public function handle_run_now() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkpp_run_now');
        $result = $this->process_due(true);
        $this->redirect_notice(sprintf('Controle afgerond: %d gebruiker(s), %d toestel(len), %d planningitem(s), %d fout(en).', $result['users'], $result['devices'], $result['items'], $result['errors']));
    }

    public function handle_test_admin() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkpp_test_admin');
        $result = $this->send_to_user(get_current_user_id(), array('title'=>'Testmelding Jaarplanner','body'=>'De pushkoppeling werkt voor jouw account.','url'=>home_url('/#responsibilities'),'tag'=>'bkpp-admin-test-'.time()));
        $this->redirect_notice($result['message']);
    }

    public function handle_clear_log() {
        if (!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkpp_clear_log');
        update_option(self::OPTION_LOG, array(), false);
        $this->redirect_notice('Het pushlog is gewist.');
    }

    public function after_season_restore() {
        // Herstelde datums vormen vanzelf nieuwe signatures. Er is geen data-import nodig.
    }
}
