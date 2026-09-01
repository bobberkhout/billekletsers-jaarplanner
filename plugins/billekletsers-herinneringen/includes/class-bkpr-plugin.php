<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BKPR_Plugin {
    const OPTION_SETTINGS = 'bkpr_settings';
    const OPTION_PEOPLE   = 'bkpr_people';
    const OPTION_LOG      = 'bkpr_log';
    const OPTION_LAST_RUN = 'bkpr_last_run';
    const CRON_HOOK       = 'bkpr_daily_check';
    const PAGE_SLUG       = 'bkpr-reminders';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_init', array($this, 'ensure_schedule'));
        add_action(self::CRON_HOOK, array($this, 'cron_run'));

        add_action('admin_post_bkpr_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_bkpr_save_people', array($this, 'handle_save_people'));
        add_action('admin_post_bkpr_save_items', array($this, 'handle_save_items'));
        add_action('admin_post_bkpr_run_now', array($this, 'handle_run_now'));
        add_action('admin_post_bkpr_test_mail', array($this, 'handle_test_mail'));
        add_action('admin_post_bkpr_clear_log', array($this, 'handle_clear_log'));
        add_action('admin_post_bkpr_clear_history', array($this, 'handle_clear_history'));

        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_bkp_project', array($this, 'save_meta_box'));
        add_action('save_post_bkp_event', array($this, 'save_meta_box'));
        add_action('save_post_bkp_task', array($this, 'save_meta_box'));
        add_action('save_post_bkp_inventory', array($this, 'save_meta_box'));
        add_action('save_post_bkp_action', array($this, 'save_meta_box'));

        // Koppeling met de centrale Billekletsers Jaarplanner-plugin. De core
        // verschuift eigen deadlines pas na de gecontroleerde seizoenspreview;
        // deze plugin registreert de wijziging en laat overige instellingen intact.
        add_action('bkp_after_season_rollover', array($this, 'handle_season_rollover'), 10, 1);
        add_action('bkp_after_season_restore', array($this, 'handle_season_restore'), 10, 1);
    }

    public static function activate() {
        $defaults = self::default_settings();
        $current = get_option(self::OPTION_SETTINGS, array());
        update_option(self::OPTION_SETTINGS, wp_parse_args(is_array($current) ? $current : array(), $defaults), false);
        if (false === get_option(self::OPTION_PEOPLE, false)) {
            add_option(self::OPTION_PEOPLE, array(), '', false);
        }
        if (false === get_option(self::OPTION_LOG, false)) {
            add_option(self::OPTION_LOG, array(), '', false);
        }
        self::clear_schedule();
        $settings = self::get_settings_static();
        if (!empty($settings['enabled'])) {
            self::schedule_next_static($settings);
        }
    }

    public static function deactivate() {
        self::clear_schedule();
    }

    private static function default_settings() {
        $admin_email = sanitize_email((string) get_option('admin_email'));
        return array(
            'enabled'            => '0',
            'send_time'          => '08:00',
            'lead_days'          => '14,7,3,1,0',
            'include_events'     => '1',
            'include_main_projects'=> '1',
            'include_projects'   => '1',
            'include_todos'      => '1',
            'include_inventories'=> '1',
            'include_actions'    => '1',
            'skip_completed'     => '1',
            'use_fallback'       => '1',
            'fallback_emails'    => $admin_email,
            'always_notify'      => '',
            'from_name'          => (string) get_bloginfo('name'),
            'from_email'         => $admin_email,
            'subject_prefix'     => '[Jaarplanning]',
        );
    }

    private static function get_settings_static() {
        $stored = get_option(self::OPTION_SETTINGS, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), self::default_settings());
    }

    private function get_settings() {
        return self::get_settings_static();
    }

    private static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function next_timestamp($time_string) {
        $time_string = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $time_string) ? $time_string : '08:00';
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $candidate = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time_string . ':00', $timezone);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate->getTimestamp();
    }

    private static function schedule_next_static($settings = null) {
        $settings = is_array($settings) ? $settings : self::get_settings_static();
        if (empty($settings['enabled'])) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(self::next_timestamp($settings['send_time']), self::CRON_HOOK);
        }
    }

    public function ensure_schedule() {
        $settings = $this->get_settings();
        if (!empty($settings['enabled'])) {
            self::schedule_next_static($settings);
        } elseif (wp_next_scheduled(self::CRON_HOOK)) {
            self::clear_schedule();
        }
    }

    public function cron_run() {
        $settings = $this->get_settings();
        if (!empty($settings['enabled'])) {
            $this->process_reminders(false);
        }
        self::schedule_next_static($settings);
    }

    public function admin_menu() {
        $parent = function_exists('bkp_admin_menu') || post_type_exists('bkp_event') ? 'bkp-planning' : 'options-general.php';
        add_submenu_page(
            $parent,
            'Herinneringsmails',
            'Herinneringsmails',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, self::PAGE_SLUG)) {
            return;
        }
        wp_enqueue_style('bkpr-admin', BKPR_URL . 'assets/admin.css', array(), BKPR_VERSION);
        wp_enqueue_script('bkpr-admin', BKPR_URL . 'assets/admin.js', array(), BKPR_VERSION, true);
    }

    private function admin_url($tab = 'overview') {
        return admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . sanitize_key($tab));
    }

    private function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_die('Je hebt geen toestemming om de herinneringsmails te beheren.');
        }
    }

    private function set_notice($type, $message) {
        set_transient('bkpr_notice_' . get_current_user_id(), array(
            'type' => $type,
            'message' => $message,
        ), 90);
    }

    private function render_notice() {
        $key = 'bkpr_notice_' . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        if (!$notice || empty($notice['message'])) {
            return;
        }
        $class = 'error' === $notice['type'] ? 'notice-error' : ('warning' === $notice['type'] ? 'notice-warning' : 'notice-success');
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    public function render_admin_page() {
        $this->require_admin();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $allowed = array('overview', 'people', 'settings', 'log');
        if (!in_array($tab, $allowed, true)) {
            $tab = 'overview';
        }

        echo '<div class="wrap bkpr-wrap">';
        echo '<h1>Herinneringsmails</h1>';
        echo '<p class="bkpr-lead">Automatische deadlineherinneringen voor de jaarplanning. Deze plugin importeert niets. Bij een gecontroleerde seizoenswissel in de Billekletsers Jaarplanner-plugin worden eigen deadlines meegenomen in het voorstel.</p>';
        $this->render_notice();
        $this->render_status_cards();
        echo '<nav class="nav-tab-wrapper">';
        $tabs = array(
            'overview' => 'Planningitems',
            'people'   => 'Personen & e-mail',
            'settings' => 'Instellingen',
            'log'      => 'Verzendlog',
        );
        foreach ($tabs as $key => $label) {
            $class = $tab === $key ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url($this->admin_url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ('people' === $tab) {
            $this->render_people_tab();
        } elseif ('settings' === $tab) {
            $this->render_settings_tab();
        } elseif ('log' === $tab) {
            $this->render_log_tab();
        } else {
            $this->render_overview_tab();
        }
        echo '</div>';
    }

    private function render_status_cards() {
        $settings = $this->get_settings();
        $next = wp_next_scheduled(self::CRON_HOOK);
        $last = get_option(self::OPTION_LAST_RUN, array());
        $timezone = wp_timezone();
        echo '<div class="bkpr-cards">';
        echo '<div class="bkpr-card"><span>Status</span><strong>' . (!empty($settings['enabled']) ? 'Ingeschakeld' : 'Uitgeschakeld') . '</strong></div>';
        echo '<div class="bkpr-card"><span>Volgende controle</span><strong>' . ($next ? esc_html(wp_date('d-m-Y H:i', $next, $timezone)) : 'Niet gepland') . '</strong></div>';
        echo '<div class="bkpr-card"><span>Laatste controle</span><strong>' . (!empty($last['time']) ? esc_html(wp_date('d-m-Y H:i', (int) $last['time'], $timezone)) : 'Nog niet uitgevoerd') . '</strong></div>';
        echo '<div class="bkpr-card"><span>Laatste resultaat</span><strong>' . (!empty($last['summary']) ? esc_html($last['summary']) : '—') . '</strong></div>';
        echo '</div>';
    }

    public function handle_season_rollover($result) {
        $result = is_array($result) ? $result : array();
        $from = sanitize_text_field((string) ($result['from'] ?? 'vorig seizoen'));
        $to = sanitize_text_field((string) ($result['to'] ?? 'nieuw seizoen'));
        $deadline_count = absint($result['reminder_deadlines'] ?? 0);
        $reset_count = absint($result['reminder_histories_reset'] ?? 0);

        $message = 'Seizoenswissel ' . $from . ' naar ' . $to . ' verwerkt.';
        if ($deadline_count) {
            $message .= ' ' . $deadline_count . ' eigen herinneringsdeadline(s) aangepast.';
        } else {
            $message .= ' Automatische herinneringsdatums volgen de nieuwe planningdatums.';
        }
        if ($reset_count) {
            $message .= ' Verzendhistorie van ' . $reset_count . ' item(s) opnieuw vrijgegeven.';
        }
        $this->add_log('info', $message, '');
    }

    public function handle_season_restore($snapshot) {
        $snapshot = is_array($snapshot) ? $snapshot : array();
        $season = sanitize_text_field((string) ($snapshot['season'] ?? 'vorig seizoen'));
        $this->add_log('info', 'Seizoensback-up van ' . $season . ' hersteld, inclusief eigen herinneringsdeadlines en verzendhistorie.', '');
    }

    private function render_overview_tab() {
        $items = $this->get_items();
        echo '<section class="bkpr-section">';
        echo '<div class="bkpr-section-head"><div><h2>Planningitems en deadlines</h2><p>Automatische datums worden uit de jaarplanning gelezen. Ontvangers worden eerst uit een handmatige e-mailoverride gehaald, daarna uit gekoppelde WordPress-gebruikers en pas daarna uit het veld Verantwoordelijke. Voor inventarisaties wordt de sluitdatum uit de Jaarplanner gebruikt. Voor projectplanning en als uitzondering bij inventarisaties kun je hier een eigen deadline vastleggen.</p></div>';
        echo '<div class="bkpr-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bkpr_run_now');
        echo '<input type="hidden" name="action" value="bkpr_run_now"><button class="button button-secondary" type="submit">Nu controleren</button></form>';
        echo '</div></div>';

        echo '<div class="bkpr-filterbar"><input type="search" id="bkpr-item-search" class="regular-text" placeholder="Zoek op onderwerp, type, verantwoordelijke of gebruiker"><select id="bkpr-type-filter"><option value="">Alle onderdelen</option>';
        foreach ($this->type_labels() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><button type="button" class="button" id="bkpr-clear-filters">Filters wissen</button><span id="bkpr-result-count"></span></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bkpr_save_items');
        echo '<input type="hidden" name="action" value="bkpr_save_items">';
        echo '<div class="bkpr-table-scroll"><table class="widefat striped bkpr-items-table"><thead><tr>';
        echo '<th>Mail</th><th>Onderdeel</th><th>Onderwerp</th><th>Verantwoordelijke</th><th>Gekoppelde gebruikers</th><th>Automatische datum</th><th>Eigen deadline</th><th>Dagen vooraf</th><th>Ontvangers overschrijven</th><th>Historie</th>';
        echo '</tr></thead><tbody>';
        if (!$items) {
            echo '<tr><td colspan="10">Er zijn nog geen ondersteunde planningitems gevonden.</td></tr>';
        }
        foreach ($items as $item) {
            $search = strtolower($item['type_label'] . ' ' . $item['project_name'] . ' ' . $item['title'] . ' ' . $item['responsible'] . ' ' . $item['linked_users_label']);
            echo '<tr class="bkpr-item-row" data-type="' . esc_attr($item['type_key']) . '" data-search="' . esc_attr($search) . '">';
            echo '<td><input type="hidden" name="rows[' . esc_attr($item['id']) . '][seen]" value="1"><label><input type="checkbox" name="rows[' . esc_attr($item['id']) . '][enabled]" value="1" ' . checked($item['enabled'], true, false) . '> actief</label></td>';
            echo '<td><span class="bkpr-badge">' . esc_html($item['type_label']) . '</span></td>';
            echo '<td><strong>' . esc_html($item['title']) . '</strong>';
            if (!empty($item['project_name']) && 'main_project' !== $item['type_key']) {
                echo '<small>Project: ' . esc_html($item['project_name']) . '</small>';
            }
            if ($item['status']) {
                echo '<small>Status: ' . esc_html($item['status']) . '</small>';
            }
            echo '</td>';
            echo '<td>' . ($item['responsible'] ? esc_html($item['responsible']) : '<span class="bkpr-muted">Niet ingevuld</span>') . '</td>';
            echo '<td>';
            $this->render_user_picker('rows[' . $item['id'] . '][user_ids]', $item['user_ids'], 'bkpr-user-picker--table');
            echo '</td>';
            echo '<td>' . ($item['source_deadline'] ? esc_html($this->format_date($item['source_deadline'])) : '<span class="bkpr-muted">Geen</span>') . '</td>';
            echo '<td><input type="date" name="rows[' . esc_attr($item['id']) . '][deadline]" value="' . esc_attr($item['custom_deadline']) . '"><small>Leeg = automatische datum</small></td>';
            echo '<td><input class="small-text" type="text" name="rows[' . esc_attr($item['id']) . '][lead_days]" value="' . esc_attr($item['lead_days']) . '" placeholder="standaard"><small>Bijv. 14,7,1,0</small></td>';
            echo '<td><textarea rows="2" name="rows[' . esc_attr($item['id']) . '][emails]" placeholder="naam@domein.nl, tweede@domein.nl">' . esc_textarea($item['emails']) . '</textarea><small>Leeg = gekoppelde gebruikers, daarna verantwoordelijke</small></td>';
            echo '<td><label><input type="checkbox" name="rows[' . esc_attr($item['id']) . '][reset]" value="1"> opnieuw mogen sturen</label></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        submit_button('Herinneringsinstellingen opslaan');
        echo '</form></section>';
    }

    private function render_people_tab() {
        $manual = $this->manual_people();
        $suggestions = $this->discovered_people();
        $rows = array();
        foreach ($manual as $entry) {
            $key = $this->normalise_name($entry['name']);
            $rows[$key] = array(
                'name' => $entry['name'],
                'emails' => $entry['emails'],
                'source' => 'Handmatig',
            );
        }
        foreach ($suggestions as $key => $entry) {
            if (!isset($rows[$key])) {
                $rows[$key] = $entry;
            } elseif (!$rows[$key]['emails'] && $entry['emails']) {
                $rows[$key]['emails'] = $entry['emails'];
            }
        }
        uasort($rows, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        echo '<section class="bkpr-section"><h2>Personen, groepen en e-mailadressen</h2>';
        echo '<p>Deze naamkoppelingen zijn de derde route: eerst gebruikt de plugin een handmatige e-mailoverride, daarna gekoppelde WordPress-gebruikers en vervolgens de namen uit <strong>Verantwoordelijke</strong>. Groepsnamen zoals “Bestuur” of een commissienaam mogen ook worden toegevoegd.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bkpr_save_people');
        echo '<input type="hidden" name="action" value="bkpr_save_people">';
        echo '<div class="bkpr-table-scroll"><table class="widefat striped"><thead><tr><th>Naam of groep</th><th>E-mailadres(sen)</th><th>Herkomst</th><th>Verwijderen</th></tr></thead><tbody>';
        $index = 0;
        foreach ($rows as $row) {
            echo '<tr><td><input class="regular-text" type="text" name="people[' . esc_attr($index) . '][name]" value="' . esc_attr($row['name']) . '"></td>';
            echo '<td><input class="large-text" type="text" name="people[' . esc_attr($index) . '][emails]" value="' . esc_attr($row['emails']) . '" placeholder="naam@domein.nl, tweede@domein.nl"></td>';
            echo '<td>' . esc_html($row['source']) . '</td><td><input type="checkbox" name="people[' . esc_attr($index) . '][delete]" value="1"></td></tr>';
            $index++;
        }
        for ($i = 0; $i < 5; $i++, $index++) {
            echo '<tr class="bkpr-new-row"><td><input class="regular-text" type="text" name="people[' . esc_attr($index) . '][name]" placeholder="Nieuwe naam of groep"></td>';
            echo '<td><input class="large-text" type="text" name="people[' . esc_attr($index) . '][emails]" placeholder="naam@domein.nl"></td><td>Nieuw</td><td></td></tr>';
        }
        echo '</tbody></table></div>';
        submit_button('E-mailkoppelingen opslaan');
        echo '</form>';
        echo '<div class="bkpr-info"><strong>Automatisch herkend:</strong> bestuursleden en commissieleden met een e-mailadres, WordPress-gebruikers en namen die in de planning als verantwoordelijke voorkomen. Gekoppelde gebruikers worden rechtstreeks via hun WordPress-account benaderd; handmatige e-mailoverrides blijven altijd leidend.</div>';
        echo '</section>';
    }

    private function render_settings_tab() {
        $settings = $this->get_settings();
        echo '<section class="bkpr-section"><h2>Algemene instellingen</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bkpr_save_settings');
        echo '<input type="hidden" name="action" value="bkpr_save_settings">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Herinneringsmails</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($settings['enabled']), true, false) . '> dagelijks controleren en mails versturen</label><p class="description">Na inschakelen worden alleen items met een geldige deadline en ontvanger verwerkt.</p></td></tr>';
        echo '<tr><th><label for="bkpr_send_time">Controle om</label></th><td><input id="bkpr_send_time" type="time" name="send_time" value="' . esc_attr($settings['send_time']) . '"><p class="description">Tijd volgens de WordPress-tijdzone.</p></td></tr>';
        echo '<tr><th><label for="bkpr_lead_days">Standaard momenten</label></th><td><input id="bkpr_lead_days" class="regular-text" type="text" name="lead_days" value="' . esc_attr($settings['lead_days']) . '"><p class="description">Aantal dagen vóór de deadline, bijvoorbeeld 14,7,3,1,0. Nul betekent op de deadlinedag.</p></td></tr>';
        echo '<tr><th>Onderdelen</th><td>';
        $parts = array(
            'include_events' => 'Jaarplanning/activiteiten',
            'include_main_projects' => 'Projecten',
            'include_projects' => 'Projectplanning',
            'include_todos' => 'To-do',
            'include_inventories' => 'Inventarisaties',
            'include_actions' => 'Acties en besluiten',
        );
        foreach ($parts as $key => $label) {
            echo '<label class="bkpr-check"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th>Afgeronde onderdelen</th><td><label><input type="checkbox" name="skip_completed" value="1" ' . checked(!empty($settings['skip_completed']), true, false) . '> geen herinnering sturen bij status afgerond, gereed, klaar, voltooid, geannuleerd of vergelijkbaar</label></td></tr>';
        echo '<tr><th>Geen e-mail gevonden</th><td><label><input type="checkbox" name="use_fallback" value="1" ' . checked(!empty($settings['use_fallback']), true, false) . '> stuur naar onderstaande algemene ontvanger(s)</label><br><input class="large-text" type="text" name="fallback_emails" value="' . esc_attr($settings['fallback_emails']) . '" placeholder="planning@domein.nl"></td></tr>';
        echo '<tr><th><label for="bkpr_always_notify">Altijd een kopie als eigen mail</label></th><td><input id="bkpr_always_notify" class="large-text" type="text" name="always_notify" value="' . esc_attr($settings['always_notify']) . '" placeholder="secretaris@domein.nl"><p class="description">Deze adressen ontvangen een eigen overzicht van alle herinneringen; adressen worden niet zichtbaar gedeeld met andere ontvangers.</p></td></tr>';
        echo '<tr><th><label for="bkpr_from_name">Afzendernaam</label></th><td><input id="bkpr_from_name" class="regular-text" type="text" name="from_name" value="' . esc_attr($settings['from_name']) . '"></td></tr>';
        echo '<tr><th><label for="bkpr_from_email">Afzender e-mail</label></th><td><input id="bkpr_from_email" class="regular-text" type="email" name="from_email" value="' . esc_attr($settings['from_email']) . '"></td></tr>';
        echo '<tr><th><label for="bkpr_subject_prefix">Onderwerpvoorvoegsel</label></th><td><input id="bkpr_subject_prefix" class="regular-text" type="text" name="subject_prefix" value="' . esc_attr($settings['subject_prefix']) . '"></td></tr>';
        echo '</tbody></table>';
        submit_button('Instellingen opslaan');
        echo '</form></section>';

        echo '<section class="bkpr-section"><h2>Test en onderhoud</h2><div class="bkpr-inline-forms">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bkpr_test_mail');
        echo '<input type="hidden" name="action" value="bkpr_test_mail"><label for="bkpr_test_email">Testadres</label> <input id="bkpr_test_email" type="email" name="test_email" value="' . esc_attr(get_option('admin_email')) . '" required> <button class="button" type="submit">Testmail sturen</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Alle verzendmarkeringen wissen? Hierdoor kunnen eerdere herinneringen opnieuw worden verstuurd.\');">';
        wp_nonce_field('bkpr_clear_history');
        echo '<input type="hidden" name="action" value="bkpr_clear_history"><button class="button" type="submit">Verzendhistorie wissen</button></form>';
        echo '</div><p class="description">Voor betrouwbare aflevering is een correct ingestelde mailserver of SMTP-configuratie op de WordPress-site nodig.</p></section>';
    }

    private function render_log_tab() {
        $log = get_option(self::OPTION_LOG, array());
        $log = is_array($log) ? $log : array();
        echo '<section class="bkpr-section"><div class="bkpr-section-head"><div><h2>Verzendlog</h2><p>De nieuwste meldingen staan bovenaan. Er worden maximaal 250 regels bewaard.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Het verzendlog wissen?\');">';
        wp_nonce_field('bkpr_clear_log');
        echo '<input type="hidden" name="action" value="bkpr_clear_log"><button class="button" type="submit">Log wissen</button></form></div>';
        echo '<div class="bkpr-table-scroll"><table class="widefat striped"><thead><tr><th>Datum en tijd</th><th>Status</th><th>Ontvanger</th><th>Melding</th></tr></thead><tbody>';
        if (!$log) {
            echo '<tr><td colspan="4">Nog geen logregels.</td></tr>';
        }
        foreach ($log as $entry) {
            $time = !empty($entry['time']) ? wp_date('d-m-Y H:i:s', (int) $entry['time'], wp_timezone()) : '—';
            echo '<tr><td>' . esc_html($time) . '</td><td><span class="bkpr-log-' . esc_attr($entry['level'] ?? 'info') . '">' . esc_html(strtoupper($entry['level'] ?? 'info')) . '</span></td><td>' . esc_html($entry['recipient'] ?? '') . '</td><td>' . esc_html($entry['message'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    public function handle_save_settings() {
        $this->require_admin();
        check_admin_referer('bkpr_save_settings');
        $old = $this->get_settings();
        $settings = array(
            'enabled'             => !empty($_POST['enabled']) ? '1' : '0',
            'send_time'           => $this->sanitise_time($_POST['send_time'] ?? '08:00'),
            'lead_days'           => implode(',', $this->parse_lead_days($_POST['lead_days'] ?? '14,7,3,1,0')),
            'include_events'      => !empty($_POST['include_events']) ? '1' : '0',
            'include_main_projects'=> !empty($_POST['include_main_projects']) ? '1' : '0',
            'include_projects'    => !empty($_POST['include_projects']) ? '1' : '0',
            'include_todos'       => !empty($_POST['include_todos']) ? '1' : '0',
            'include_inventories' => !empty($_POST['include_inventories']) ? '1' : '0',
            'include_actions'     => !empty($_POST['include_actions']) ? '1' : '0',
            'skip_completed'      => !empty($_POST['skip_completed']) ? '1' : '0',
            'use_fallback'        => !empty($_POST['use_fallback']) ? '1' : '0',
            'fallback_emails'     => implode(', ', $this->parse_emails($_POST['fallback_emails'] ?? '')),
            'always_notify'       => implode(', ', $this->parse_emails($_POST['always_notify'] ?? '')),
            'from_name'           => sanitize_text_field(wp_unslash($_POST['from_name'] ?? get_bloginfo('name'))),
            'from_email'          => sanitize_email(wp_unslash($_POST['from_email'] ?? get_option('admin_email'))),
            'subject_prefix'      => sanitize_text_field(wp_unslash($_POST['subject_prefix'] ?? '[Jaarplanning]')),
        );
        if (!$settings['from_email']) {
            $settings['from_email'] = sanitize_email((string) get_option('admin_email'));
        }
        update_option(self::OPTION_SETTINGS, $settings, false);
        if ($old['send_time'] !== $settings['send_time'] || $old['enabled'] !== $settings['enabled']) {
            self::clear_schedule();
        }
        self::schedule_next_static($settings);
        $this->set_notice('success', 'De instellingen voor herinneringsmails zijn opgeslagen.');
        wp_safe_redirect($this->admin_url('settings'));
        exit;
    }

    public function handle_save_people() {
        $this->require_admin();
        check_admin_referer('bkpr_save_people');
        $posted = isset($_POST['people']) ? (array) wp_unslash($_POST['people']) : array();
        $out = array();
        $seen = array();
        foreach ($posted as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $name = sanitize_text_field($row['name'] ?? '');
            $emails = $this->parse_emails($row['emails'] ?? '');
            if ('' === $name || !$emails) {
                continue;
            }
            $key = $this->normalise_name($name);
            if (!$key) {
                continue;
            }
            if (isset($seen[$key])) {
                $out[$seen[$key]]['emails'] = implode(', ', array_values(array_unique(array_merge($this->parse_emails($out[$seen[$key]]['emails']), $emails))));
                continue;
            }
            $seen[$key] = count($out);
            $out[] = array('name' => $name, 'emails' => implode(', ', $emails));
        }
        update_option(self::OPTION_PEOPLE, $out, false);
        $this->set_notice('success', count($out) . ' e-mailkoppeling(en) opgeslagen.');
        wp_safe_redirect($this->admin_url('people'));
        exit;
    }

    public function handle_save_items() {
        $this->require_admin();
        check_admin_referer('bkpr_save_items');
        $rows = isset($_POST['rows']) ? (array) wp_unslash($_POST['rows']) : array();
        $saved = 0;
        foreach ($rows as $post_id => $row) {
            $post_id = absint($post_id);
            if (!$post_id || !is_array($row) || empty($row['seen']) || !$this->is_supported_post($post_id)) {
                continue;
            }
            update_post_meta($post_id, '_bkpr_enabled', !empty($row['enabled']) ? '1' : '0');
            $deadline = $this->sanitise_date($row['deadline'] ?? '');
            if ($deadline) {
                update_post_meta($post_id, '_bkpr_deadline', $deadline);
            } else {
                delete_post_meta($post_id, '_bkpr_deadline');
            }
            $lead_days = trim((string) ($row['lead_days'] ?? ''));
            if ('' !== $lead_days) {
                update_post_meta($post_id, '_bkpr_lead_days', implode(',', $this->parse_lead_days($lead_days)));
            } else {
                delete_post_meta($post_id, '_bkpr_lead_days');
            }
            $user_ids = $this->sanitise_user_ids($row['user_ids'] ?? array());
            if ($user_ids) {
                update_post_meta($post_id, '_bkp_assigned_user_ids', $user_ids);
            } else {
                delete_post_meta($post_id, '_bkp_assigned_user_ids');
            }
            $emails = $this->parse_emails($row['emails'] ?? '');
            if ($emails) {
                update_post_meta($post_id, '_bkpr_recipient_emails', implode(', ', $emails));
            } else {
                delete_post_meta($post_id, '_bkpr_recipient_emails');
            }
            if (!empty($row['reset'])) {
                delete_post_meta($post_id, '_bkpr_sent_signatures');
            }
            $saved++;
        }
        $this->set_notice('success', $saved . ' planningitem(s) bijgewerkt. Bestaande planningdata is niet gewijzigd.');
        wp_safe_redirect($this->admin_url('overview'));
        exit;
    }

    public function handle_run_now() {
        $this->require_admin();
        check_admin_referer('bkpr_run_now');
        $result = $this->process_reminders(true);
        $message = sprintf('Controle voltooid: %d mail(s) verzonden, %d item-ontvangercombinatie(s) verwerkt en %d fout(en).', $result['mails'], $result['items'], $result['errors']);
        $this->set_notice($result['errors'] ? 'warning' : 'success', $message);
        wp_safe_redirect($this->admin_url('overview'));
        exit;
    }

    public function handle_test_mail() {
        $this->require_admin();
        check_admin_referer('bkpr_test_mail');
        $email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!$email) {
            $this->set_notice('error', 'Vul een geldig testadres in.');
            wp_safe_redirect($this->admin_url('settings'));
            exit;
        }
        $settings = $this->get_settings();
        $subject = trim($settings['subject_prefix'] . ' Testmail');
        $message = $this->email_shell('<h2>Test geslaagd</h2><p>Dit is een test van de herinneringsmail-plugin voor de jaarplanning.</p><p>Als deze mail aankomt, kan WordPress vanaf deze website mail aanbieden aan de ingestelde mailserver.</p>');
        $sent = wp_mail($email, $subject, $message, $this->mail_headers($settings));
        $this->add_log($sent ? 'success' : 'error', $sent ? 'Testmail aangeboden aan de mailserver.' : 'Testmail kon niet worden aangeboden aan de mailserver.', $email);
        $this->set_notice($sent ? 'success' : 'error', $sent ? 'De testmail is aangeboden aan WordPress. Controleer ook de inbox en spammap.' : 'De testmail kon niet worden verzonden. Controleer de mail- of SMTP-instellingen.');
        wp_safe_redirect($this->admin_url('settings'));
        exit;
    }

    public function handle_clear_log() {
        $this->require_admin();
        check_admin_referer('bkpr_clear_log');
        update_option(self::OPTION_LOG, array(), false);
        $this->set_notice('success', 'Het verzendlog is gewist.');
        wp_safe_redirect($this->admin_url('log'));
        exit;
    }

    public function handle_clear_history() {
        $this->require_admin();
        check_admin_referer('bkpr_clear_history');
        $ids = get_posts(array(
            'post_type' => array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action'),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        foreach ($ids as $id) {
            delete_post_meta($id, '_bkpr_sent_signatures');
        }
        $this->set_notice('success', 'De verzendhistorie is gewist. Eerdere herinneringsmomenten kunnen opnieuw worden verstuurd.');
        wp_safe_redirect($this->admin_url('settings'));
        exit;
    }

    public function add_meta_boxes() {
        foreach (array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action') as $post_type) {
            add_meta_box('bkpr_reminder', 'Herinneringsmail', array($this, 'render_meta_box'), $post_type, 'side', 'default');
        }
    }

    public function render_meta_box($post) {
        wp_nonce_field('bkpr_save_meta', 'bkpr_meta_nonce');
        $item = $this->item_from_post($post);
        if (!$item) {
            echo '<p>Dit item wordt niet ondersteund.</p>';
            return;
        }
        echo '<p><label><input type="checkbox" name="bkpr_enabled" value="1" ' . checked($item['enabled'], true, false) . '> herinneringen actief</label></p>';
        echo '<p><strong>Automatische datum</strong><br>' . ($item['source_deadline'] ? esc_html($this->format_date($item['source_deadline'])) : '<span class="description">Geen automatische datum</span>') . '</p>';
        echo '<p><label for="bkpr_deadline"><strong>Eigen deadline</strong></label><br><input type="date" id="bkpr_deadline" name="bkpr_deadline" value="' . esc_attr($item['custom_deadline']) . '"></p>';
        echo '<p><label for="bkpr_lead_days"><strong>Dagen vooraf</strong></label><br><input class="widefat" type="text" id="bkpr_lead_days" name="bkpr_lead_days" value="' . esc_attr($item['lead_days']) . '" placeholder="standaard"></p>';
        echo '<p><strong>Gekoppelde WordPress-gebruikers</strong></p>';
        if (function_exists('bkp_render_user_picker')) {
            echo '<p>' . ($item['linked_users_label'] ? esc_html($item['linked_users_label']) : '<span class="description">Geen gebruiker gekoppeld</span>') . '</p>';
            echo '<p class="description">Wijzig deze koppeling in het blok met planninggegevens of in Jaarplanning → Herinneringsmails.</p>';
        } else {
            echo '<input type="hidden" name="bkpr_user_ids_present" value="1">';
            $this->render_user_picker('bkpr_user_ids', $item['user_ids'], 'bkpr-user-picker--meta');
        }
        echo '<p><label for="bkpr_emails"><strong>Ontvangers overschrijven</strong></label><br><textarea class="widefat" rows="3" id="bkpr_emails" name="bkpr_emails" placeholder="naam@domein.nl">' . esc_textarea($item['emails']) . '</textarea></p>';
        echo '<p class="description">Volgorde: handmatige e-mailadressen → gekoppelde gebruikers → Verantwoordelijke → algemene fallback.</p>';
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['bkpr_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkpr_meta_nonce'])), 'bkpr_save_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        update_post_meta($post_id, '_bkpr_enabled', !empty($_POST['bkpr_enabled']) ? '1' : '0');
        $deadline = $this->sanitise_date($_POST['bkpr_deadline'] ?? '');
        if ($deadline) {
            update_post_meta($post_id, '_bkpr_deadline', $deadline);
        } else {
            delete_post_meta($post_id, '_bkpr_deadline');
        }
        $lead_days = trim((string) wp_unslash($_POST['bkpr_lead_days'] ?? ''));
        if ('' !== $lead_days) {
            update_post_meta($post_id, '_bkpr_lead_days', implode(',', $this->parse_lead_days($lead_days)));
        } else {
            delete_post_meta($post_id, '_bkpr_lead_days');
        }
        if (!empty($_POST['bkpr_user_ids_present'])) {
            $user_ids = $this->sanitise_user_ids(isset($_POST['bkpr_user_ids']) ? (array) wp_unslash($_POST['bkpr_user_ids']) : array());
            if ($user_ids) {
                update_post_meta($post_id, '_bkp_assigned_user_ids', $user_ids);
            } else {
                delete_post_meta($post_id, '_bkp_assigned_user_ids');
            }
        }
        $emails = $this->parse_emails($_POST['bkpr_emails'] ?? '');
        if ($emails) {
            update_post_meta($post_id, '_bkpr_recipient_emails', implode(', ', $emails));
        } else {
            delete_post_meta($post_id, '_bkpr_recipient_emails');
        }
    }

    private function type_labels() {
        return array(
            'main_project' => 'Project',
            'event' => 'Jaarplanning',
            'project' => 'Projectplanning',
            'todo' => 'To-do',
            'inventory' => 'Inventarisatie',
            'action' => 'Actie/besluit',
            'task' => 'Planningstaak',
        );
    }

    private function is_supported_post($post_id) {
        return in_array(get_post_type($post_id), array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action'), true);
    }

    private function get_items() {
        $posts = get_posts(array(
            'post_type' => array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action'),
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        $items = array();
        foreach ($posts as $post) {
            $item = $this->item_from_post($post);
            if ($item) {
                $items[] = $item;
            }
        }
        usort($items, function($a, $b) {
            $type = strcasecmp($a['type_label'], $b['type_label']);
            return 0 !== $type ? $type : strcasecmp($a['title'], $b['title']);
        });
        return $items;
    }

    private function item_from_post($post) {
        if (is_numeric($post)) {
            $post = get_post((int) $post);
        }
        if (!$post instanceof WP_Post || !$this->is_supported_post($post->ID)) {
            return null;
        }
        $type_key = 'task';
        $type_label = 'Planningstaak';
        $source_deadline = '';
        $status = '';
        if ('bkp_project' === $post->post_type) {
            $type_key = 'main_project';
            $type_label = 'Project';
            $source_deadline = (string) get_post_meta($post->ID, '_bkp_project_end', true);
            $status = (string) get_post_meta($post->ID, '_bkp_project_status', true);
        } elseif ('bkp_event' === $post->post_type) {
            $type_key = 'event';
            $type_label = 'Jaarplanning';
            $source_deadline = (string) get_post_meta($post->ID, '_bkp_date', true);
            $status = '1' === (string) get_post_meta($post->ID, '_bkp_confirmed', true) ? 'Vastgesteld' : 'Concept';
        } elseif ('bkp_inventory' === $post->post_type) {
            $type_key = 'inventory';
            $type_label = 'Inventarisatie';
            $source_deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
            if ('1' !== (string) get_post_meta($post->ID, '_bkp_inventory_open', true)) {
                $status = 'Gesloten';
            }
        } elseif ('bkp_action' === $post->post_type) {
            $type_key = 'action';
            $type_label = 'Actie/besluit';
            $source_deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            $status = (string) get_post_meta($post->ID, '_bkp_done', true);
        } elseif ('bkp_task' === $post->post_type) {
            $category = (string) get_post_meta($post->ID, '_bkp_category', true);
            if ('To-do' === $category) {
                $type_key = 'todo';
                $type_label = 'To-do';
                $source_deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            } elseif ('Projectplanning' === $category) {
                $type_key = 'project';
                $type_label = 'Projectplanning';
                $source_deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
            }
            $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
        }
        $source_deadline = $this->sanitise_date($source_deadline);
        $custom_deadline = $this->sanitise_date(get_post_meta($post->ID, '_bkpr_deadline', true));
        $enabled_meta = get_post_meta($post->ID, '_bkpr_enabled', true);
        return array(
            'id' => (int) $post->ID,
            'post_type' => $post->post_type,
            'type_key' => $type_key,
            'type_label' => $type_label,
            'title' => $post->post_title,
            'project_id' => 'bkp_project' === $post->post_type ? (int) $post->ID : absint(get_post_meta($post->ID, '_bkp_project_id', true)),
            'project_name' => 'bkp_project' === $post->post_type ? $post->post_title : (function_exists('bkp_project_name') ? bkp_project_name(absint(get_post_meta($post->ID, '_bkp_project_id', true)), '') : ''),
            'responsible' => (string) get_post_meta($post->ID, '_bkp_responsible', true),
            'source_deadline' => $source_deadline,
            'custom_deadline' => $custom_deadline,
            'deadline' => $custom_deadline ?: $source_deadline,
            'lead_days' => (string) get_post_meta($post->ID, '_bkpr_lead_days', true),
            'emails' => (string) get_post_meta($post->ID, '_bkpr_recipient_emails', true),
            'user_ids' => $this->sanitise_user_ids(get_post_meta($post->ID, '_bkp_assigned_user_ids', true)),
            'linked_users_label' => $this->linked_user_label(get_post_meta($post->ID, '_bkp_assigned_user_ids', true)),
            'status' => $status,
            'enabled' => '' === $enabled_meta ? true : ('1' === $enabled_meta),
        );
    }

    private function include_item_type($item, $settings) {
        $map = array(
            'main_project' => 'include_main_projects',
            'event' => 'include_events',
            'project' => 'include_projects',
            'todo' => 'include_todos',
            'inventory' => 'include_inventories',
            'action' => 'include_actions',
            'task' => 'include_projects',
        );
        $key = $map[$item['type_key']] ?? '';
        return $key && !empty($settings[$key]);
    }

    private function is_completed($item) {
        $status = strtolower(remove_accents(trim((string) $item['status'])));
        if ('' === $status) {
            return false;
        }
        $status = preg_replace('/[^a-z0-9]+/', ' ', $status);
        $exact = array('ja', 'yes', 'done', 'af', 'klaar', 'gereed', 'afgerond', 'voltooid', 'completed', 'geannuleerd', 'gearchiveerd', 'archief', 'vervallen', 'gesloten');
        if (in_array(trim($status), $exact, true)) {
            return true;
        }
        foreach (array('afgerond', 'voltooid', 'completed', 'geannuleerd', 'gearchiveerd', 'vervallen', 'niet meer nodig') as $word) {
            if (false !== strpos($status, $word)) {
                return true;
            }
        }
        return false;
    }

    private function process_reminders($manual = false) {
        $settings = $this->get_settings();
        $result = array('mails' => 0, 'items' => 0, 'errors' => 0);
        if (empty($settings['enabled']) && !$manual) {
            return $result;
        }
        $today = new DateTimeImmutable('today', wp_timezone());
        $directory = $this->email_directory();
        $digests = array();
        $global_days = $this->parse_lead_days($settings['lead_days']);
        $always_notify = $this->parse_emails($settings['always_notify']);

        foreach ($this->get_items() as $item) {
            if (!$item['enabled'] || !$this->include_item_type($item, $settings) || !$item['deadline']) {
                continue;
            }
            if (!empty($settings['skip_completed']) && $this->is_completed($item)) {
                continue;
            }
            $deadline = DateTimeImmutable::createFromFormat('!Y-m-d', $item['deadline'], wp_timezone());
            if (!$deadline) {
                continue;
            }
            $days_until = (int) $today->diff($deadline)->format('%r%a');
            $lead_days = '' !== trim($item['lead_days']) ? $this->parse_lead_days($item['lead_days']) : $global_days;
            if (!in_array($days_until, $lead_days, true)) {
                continue;
            }
            $recipients = $this->resolve_recipients($item, $directory, $settings);
            foreach ($always_notify as $email) {
                $recipients[] = $email;
            }
            $recipients = array_values(array_unique($recipients));
            if (!$recipients) {
                $this->add_log('warning', 'Geen ontvanger gevonden voor “' . $item['title'] . '”.', '');
                continue;
            }
            foreach ($recipients as $email) {
                $signature = $this->signature($item, $days_until, $email);
                if ($this->signature_sent($item['id'], $signature)) {
                    continue;
                }
                if (!isset($digests[$email])) {
                    $digests[$email] = array();
                }
                $digests[$email][] = array(
                    'item' => $item,
                    'days_until' => $days_until,
                    'signature' => $signature,
                );
            }
        }

        foreach ($digests as $email => $entries) {
            $count = count($entries);
            $subject = trim($settings['subject_prefix'] . ' ' . $count . ' deadlineherinnering' . (1 === $count ? '' : 'en'));
            $message = $this->build_digest($entries);
            $sent = wp_mail($email, $subject, $message, $this->mail_headers($settings));
            if ($sent) {
                $result['mails']++;
                $result['items'] += $count;
                foreach ($entries as $entry) {
                    $this->mark_signature_sent($entry['item']['id'], $entry['signature']);
                }
                $this->add_log('success', $count . ' herinnering(en) aangeboden aan de mailserver.', $email);
            } else {
                $result['errors']++;
                $this->add_log('error', 'Mail met ' . $count . ' herinnering(en) kon niet worden aangeboden aan de mailserver.', $email);
            }
        }

        $summary = $result['mails'] . ' mail(s), ' . $result['items'] . ' item(s), ' . $result['errors'] . ' fout(en)';
        update_option(self::OPTION_LAST_RUN, array('time' => time(), 'summary' => $summary), false);
        if (!$digests) {
            $this->add_log('info', 'Controle uitgevoerd; er waren vandaag geen nieuwe herinneringen te versturen.', '');
        }
        return $result;
    }

    private function build_digest($entries) {
        $rows = '';
        foreach ($entries as $entry) {
            $item = $entry['item'];
            $days = (int) $entry['days_until'];
            if (0 === $days) {
                $when = 'Vandaag';
            } elseif (1 === $days) {
                $when = 'Morgen';
            } else {
                $when = 'Over ' . $days . ' dagen';
            }
            $rows .= '<tr>';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #eadede;">' . esc_html($item['type_label']) . '</td>';
            $project_line = (!empty($item['project_name']) && 'main_project' !== $item['type_key']) ? '<br><small>Project: ' . esc_html($item['project_name']) . '</small>' : '';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #eadede;"><strong>' . esc_html($item['title']) . '</strong>' . $project_line . ($item['responsible'] ? '<br><small>Verantwoordelijke: ' . esc_html($item['responsible']) . '</small>' : '') . '</td>';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #eadede;white-space:nowrap;">' . esc_html($this->format_date($item['deadline'])) . '<br><strong style="color:#b20f20;">' . esc_html($when) . '</strong></td>';
            $rows .= '</tr>';
        }
        $content = '<h2>Deadlineherinneringen</h2><p>Onderstaande onderdelen uit de jaarplanning naderen hun deadline.</p>';
        $content .= '<table role="presentation" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eadede;"><thead><tr style="background:#f7eeee;"><th style="text-align:left;padding:10px;">Onderdeel</th><th style="text-align:left;padding:10px;">Onderwerp</th><th style="text-align:left;padding:10px;">Deadline</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $content .= '<p style="margin-top:22px;"><a href="' . esc_url(home_url('/')) . '" style="display:inline-block;background:#b20f20;color:#fff;text-decoration:none;padding:11px 18px;border-radius:5px;font-weight:bold;">Open de jaarplanning</a></p>';
        $content .= '<p style="color:#666;font-size:12px;">Deze mail is automatisch verstuurd door de Billekletsers-jaarplanning.</p>';
        return $this->email_shell($content);
    }

    private function email_shell($content) {
        $site_name = get_bloginfo('name');
        return '<!doctype html><html><body style="margin:0;background:#f5f2f2;font-family:Arial,sans-serif;color:#2c2020;"><div style="max-width:760px;margin:0 auto;padding:24px;"><div style="background:#b20f20;color:#fff;padding:18px 22px;border-radius:8px 8px 0 0;"><strong style="font-size:20px;">' . esc_html($site_name) . '</strong></div><div style="background:#fff;padding:24px;border:1px solid #eadede;border-top:0;border-radius:0 0 8px 8px;">' . $content . '</div></div></body></html>';
    }

    private function mail_headers($settings) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if (!empty($settings['from_email'])) {
            $from_name = trim((string) $settings['from_name']);
            $headers[] = 'From: ' . ($from_name ? $from_name . ' ' : '') . '<' . $settings['from_email'] . '>';
        }
        return $headers;
    }

    private function signature($item, $days_until, $email) {
        return hash('sha256', $item['deadline'] . '|' . (int) $days_until . '|' . strtolower($email));
    }

    private function signature_sent($post_id, $signature) {
        $sent = get_post_meta($post_id, '_bkpr_sent_signatures', true);
        return is_array($sent) && in_array($signature, $sent, true);
    }

    private function mark_signature_sent($post_id, $signature) {
        $sent = get_post_meta($post_id, '_bkpr_sent_signatures', true);
        $sent = is_array($sent) ? $sent : array();
        $sent[] = $signature;
        $sent = array_values(array_unique($sent));
        if (count($sent) > 200) {
            $sent = array_slice($sent, -200);
        }
        update_post_meta($post_id, '_bkpr_sent_signatures', $sent);
    }

    private function add_log($level, $message, $recipient = '') {
        $log = get_option(self::OPTION_LOG, array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, array(
            'time' => time(),
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'recipient' => sanitize_email($recipient),
        ));
        if (count($log) > 250) {
            $log = array_slice($log, 0, 250);
        }
        update_option(self::OPTION_LOG, $log, false);
    }

    private function assignable_users() {
        $users = get_users(array(
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name', 'user_email'),
        ));
        $out = array();
        foreach ((array) $users as $user) {
            $email = sanitize_email((string) $user->user_email);
            if (!$email || !is_email($email)) {
                continue;
            }
            $out[] = $user;
        }
        return $out;
    }

    private function sanitise_user_ids($value) {
        $ids = is_array($value) ? $value : preg_split('/[^0-9]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if (!$id || in_array($id, $out, true)) {
                continue;
            }
            $user = get_userdata($id);
            if (!$user || !is_email((string) $user->user_email)) {
                continue;
            }
            $out[] = $id;
        }
        return $out;
    }

    private function emails_for_user_ids($value) {
        $emails = array();
        foreach ($this->sanitise_user_ids($value) as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_email((string) $user->user_email)) {
                continue;
            }
            // De pushmodule bewaart de persoonlijke keuze in user meta.
            // Leeg of onbekend blijft bewust achterwaarts compatibel: e-mail + push.
            $preference = sanitize_key((string) get_user_meta($user_id, 'bkpp_notification_preference', true));
            $allows_email = !in_array($preference, array('push', 'none'), true);
            $allows_email = (bool) apply_filters('bkpr_user_allows_email', $allows_email, $user_id, $preference);
            if ($allows_email) {
                $emails[] = strtolower(sanitize_email((string) $user->user_email));
            }
        }
        return array_values(array_unique($emails));
    }

    private function linked_user_label($value) {
        $names = array();
        foreach ($this->sanitise_user_ids($value) as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $names[] = $user->display_name;
            }
        }
        return implode(', ', array_values(array_unique(array_filter($names))));
    }

    private function user_picker_summary($selected_ids) {
        $names = array();
        foreach ($this->sanitise_user_ids($selected_ids) as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $names[] = $user->display_name;
            }
        }
        $names = array_values(array_unique(array_filter($names)));
        if (!$names) {
            return 'Geen gebruiker gekoppeld';
        }
        return count($names) <= 2 ? implode(', ', $names) : count($names) . ' gebruikers gekoppeld';
    }

    private function render_user_picker($name, $selected_ids = array(), $class = '') {
        $selected_ids = $this->sanitise_user_ids($selected_ids);
        $users = $this->assignable_users();
        echo '<details class="bkpr-user-picker ' . esc_attr($class) . '">';
        echo '<summary data-bkpr-user-summary>' . esc_html($this->user_picker_summary($selected_ids)) . '</summary>';
        echo '<div class="bkpr-user-picker-list">';
        if (!$users) {
            echo '<p>Er zijn nog geen WordPress-gebruikers met een geldig e-mailadres.</p>';
        } else {
            foreach ($users as $user) {
                $checked = in_array((int) $user->ID, $selected_ids, true);
                echo '<label><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($user->ID) . '" ' . checked($checked, true, false) . '><span>' . esc_html($user->display_name) . '</span><small>' . esc_html($user->user_email) . '</small></label>';
            }
        }
        echo '</div></details>';
    }

    private function manual_people() {
        $stored = get_option(self::OPTION_PEOPLE, array());
        $out = array();
        foreach ((array) $stored as $row) {
            $name = sanitize_text_field((string) ($row['name'] ?? ''));
            $emails = $this->parse_emails($row['emails'] ?? '');
            if ($name && $emails) {
                $out[] = array('name' => $name, 'emails' => implode(', ', $emails));
            }
        }
        return $out;
    }

    private function discovered_people() {
        $out = array();
        $add = function($name, $emails, $source) use (&$out) {
            $name = sanitize_text_field((string) $name);
            if (!$name) {
                return;
            }
            $key = $this->normalise_name($name);
            if (!$key) {
                return;
            }
            $email_list = $this->parse_emails($emails);
            if (!isset($out[$key])) {
                $out[$key] = array('name' => $name, 'emails' => implode(', ', $email_list), 'source' => $source);
            } elseif ($email_list) {
                $combined = array_values(array_unique(array_merge($this->parse_emails($out[$key]['emails']), $email_list)));
                $out[$key]['emails'] = implode(', ', $combined);
            }
        };

        $ids = get_posts(array(
            'post_type' => array('bkp_project', 'bkp_event', 'bkp_task', 'bkp_inventory', 'bkp_action'),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        foreach ($ids as $id) {
            $responsible = (string) get_post_meta($id, '_bkp_responsible', true);
            foreach ($this->split_names($responsible) as $name) {
                $add($name, '', 'Planning');
            }
        }

        $board = get_option('bkp_board', array());
        $board_emails = array();
        foreach ((array) $board as $member) {
            $name = (string) ($member['name'] ?? '');
            $email = (string) ($member['email'] ?? '');
            $add($name, $email, 'Bestuur');
            $board_emails = array_merge($board_emails, $this->parse_emails($email));
        }
        if ($board_emails) {
            $add('Bestuur', $board_emails, 'Bestuur');
        }

        $committees = get_option('bkp_committees', array());
        $groups = array();
        foreach ((array) $committees as $member) {
            $name = (string) ($member['name'] ?? '');
            $email = (string) ($member['email'] ?? '');
            $committee = (string) ($member['committee'] ?? '');
            $add($name, $email, $committee ? 'Commissie: ' . $committee : 'Commissie');
            if ($committee && $email) {
                if (!isset($groups[$committee])) {
                    $groups[$committee] = array();
                }
                $groups[$committee] = array_merge($groups[$committee], $this->parse_emails($email));
            }
        }
        foreach ($groups as $committee => $emails) {
            $add($committee, $emails, 'Commissiegroep');
        }

        $users = get_users(array('fields' => array('ID', 'display_name', 'user_email')));
        foreach ($users as $user) {
            $add($user->display_name, $user->user_email, 'WordPress-gebruiker');
        }
        return $out;
    }

    private function email_directory() {
        $directory = array();
        foreach ($this->discovered_people() as $key => $entry) {
            $directory[$key] = $this->parse_emails($entry['emails']);
        }
        foreach ($this->manual_people() as $entry) {
            $key = $this->normalise_name($entry['name']);
            if ($key) {
                $directory[$key] = $this->parse_emails($entry['emails']);
            }
        }
        return $directory;
    }

    private function resolve_recipients($item, $directory, $settings) {
        $override = $this->parse_emails($item['emails']);
        if ($override) {
            return $override;
        }
        $emails = $this->emails_for_user_ids($item['user_ids'] ?? array());
        if ($emails) {
            return $emails;
        }
        $responsible = trim((string) $item['responsible']);
        $emails = array();
        if ($responsible) {
            $exact = $this->normalise_name($responsible);
            if ($exact && !empty($directory[$exact])) {
                $emails = array_merge($emails, $directory[$exact]);
            }
            foreach ($this->split_names($responsible) as $name) {
                $key = $this->normalise_name($name);
                if ($key && !empty($directory[$key])) {
                    $emails = array_merge($emails, $directory[$key]);
                }
            }
        }
        $emails = array_values(array_unique($emails));
        if (!$emails && !empty($settings['use_fallback'])) {
            $emails = $this->parse_emails($settings['fallback_emails']);
        }
        return $emails;
    }

    private function split_names($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return array();
        }
        $parts = preg_split('/\s*(?:,|;|\r?\n|\s+&\s+|\s+en\s+)\s*/ui', $value);
        $out = array();
        foreach ((array) $parts as $part) {
            $part = trim($part);
            if ($part) {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    private function normalise_name($value) {
        $value = strtolower(remove_accents(trim((string) $value)));
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private function sanitise_time($value) {
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '08:00';
    }

    private function sanitise_date($value) {
        $value = sanitize_text_field((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return '';
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : '';
    }

    private function format_date($value) {
        $date = $this->sanitise_date($value);
        if (!$date) {
            return '';
        }
        $timestamp = strtotime($date . ' 12:00:00');
        return wp_date('d-m-Y', $timestamp, wp_timezone());
    }

    private function parse_lead_days($value) {
        $parts = is_array($value) ? $value : preg_split('/[^0-9-]+/', (string) $value);
        $days = array();
        foreach ((array) $parts as $part) {
            if ('' === (string) $part) {
                continue;
            }
            $day = (int) $part;
            if ($day < 0 || $day > 365) {
                continue;
            }
            $days[] = $day;
        }
        $days = array_values(array_unique($days));
        rsort($days, SORT_NUMERIC);
        return $days ?: array(14, 7, 3, 1, 0);
    }

    private function parse_emails($value) {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\s,;]+/', (string) wp_unslash($value));
        }
        $out = array();
        foreach ((array) $parts as $part) {
            $email = sanitize_email(trim((string) $part));
            if ($email && is_email($email)) {
                $out[] = strtolower($email);
            }
        }
        return array_values(array_unique($out));
    }
}
