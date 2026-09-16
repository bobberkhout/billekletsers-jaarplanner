<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Openbare registratie voor leden (werkend of niet).
 * Een aanvraag maakt nog geen WordPress-account aan. Pas na goedkeuring door
 * een beheerder wordt een account met de rol Abonnee aangemaakt.
 */

function bkp_registration_is_open() {
    return (string) get_option('bkp_registration_open', '1') === '1';
}

function bkp_registration_url() {
    return add_query_arg('bkp_register', '1', home_url('/'));
}

function bkp_register_registration_post_type() {
    register_post_type('bkp_registration', array(
        'labels' => array(
            'name'          => 'Registratieaanvragen',
            'singular_name' => 'Registratieaanvraag',
        ),
        'public'              => false,
        'show_ui'             => false,
        'show_in_menu'        => false,
        'show_in_rest'        => false,
        'supports'            => array('title'),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
    ));
}
add_action('init', 'bkp_register_registration_post_type');

function bkp_registration_status($post_id) {
    $status = sanitize_key((string) get_post_meta($post_id, '_bkp_registration_status', true));
    return in_array($status, array('pending', 'approved', 'rejected'), true) ? $status : 'pending';
}

function bkp_registration_pending_count() {
    $query = new WP_Query(array(
        'post_type'      => 'bkp_registration',
        'post_status'    => array('pending', 'private', 'draft', 'publish'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(array(
            'key'   => '_bkp_registration_status',
            'value' => 'pending',
        )),
        'no_found_rows'  => false,
    ));
    return (int) $query->found_posts;
}

function bkp_registration_admin_menu() {
    $count = bkp_registration_pending_count();
    $label = 'Registratieaanvragen';
    if ($count > 0) {
        $label .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
    }
    add_submenu_page(
        'bkp-planning',
        'Registratieaanvragen',
        $label,
        'manage_options',
        'bkp-registrations',
        'bkp_registration_admin_page'
    );
}
add_action('admin_menu', 'bkp_registration_admin_menu', 20);

function bkp_registration_client_hash() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    return hash_hmac('sha256', $ip, wp_salt('nonce'));
}

function bkp_registration_find_pending_by_email($email) {
    $ids = get_posts(array(
        'post_type'      => 'bkp_registration',
        'post_status'    => array('pending', 'private', 'draft', 'publish'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            'relation' => 'AND',
            array('key' => '_bkp_registration_email', 'value' => $email),
            array('key' => '_bkp_registration_status', 'value' => 'pending'),
        ),
    ));
    return $ids ? (int) $ids[0] : 0;
}

function bkp_registration_form_values() {
    return array(
        'name'       => sanitize_text_field(wp_unslash($_POST['bkp_reg_name'] ?? '')),
        'email'      => sanitize_email(wp_unslash($_POST['bkp_reg_email'] ?? '')),
        'phone'      => sanitize_text_field(wp_unslash($_POST['bkp_reg_phone'] ?? '')),
        'role'       => sanitize_text_field(wp_unslash($_POST['bkp_reg_role'] ?? '')),
        'motivation' => sanitize_textarea_field(wp_unslash($_POST['bkp_reg_motivation'] ?? '')),
    );
}

function bkp_registration_process_public_submission() {
    $result = array('success' => false, 'message' => '', 'values' => bkp_registration_form_values());
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['bkp_registration_submit'])) {
        return $result;
    }

    if (!bkp_registration_is_open()) {
        $result['message'] = 'Registreren is momenteel gesloten.';
        return $result;
    }

    if (!empty($_POST['bkp_reg_website'])) {
        $result['message'] = 'De aanvraag kon niet worden verwerkt.';
        return $result;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['bkp_registration_nonce'] ?? ''));
    if (!$nonce || !wp_verify_nonce($nonce, 'bkp_submit_registration')) {
        $result['message'] = 'De sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
        return $result;
    }

    $rate_key = 'bkp_reg_rate_' . substr(bkp_registration_client_hash(), 0, 32);
    $attempts = (int) get_transient($rate_key);
    if ($attempts >= 5) {
        $result['message'] = 'Er zijn te veel aanvragen vanaf dit apparaat gedaan. Probeer het later opnieuw.';
        return $result;
    }
    set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

    $values = $result['values'];
    if ($values['name'] === '' || $values['email'] === '') {
        $result['message'] = 'Vul je naam en e-mailadres in.';
        return $result;
    }
    if (!is_email($values['email'])) {
        $result['message'] = 'Vul een geldig e-mailadres in.';
        return $result;
    }
    if (empty($_POST['bkp_reg_consent'])) {
        $result['message'] = 'Bevestig dat je een persoonlijk account voor de interne jaarplanner aanvraagt.';
        return $result;
    }
    if (email_exists($values['email'])) {
        $result['message'] = 'Voor dit e-mailadres bestaat al een account. Gebruik de knop Inloggen of vraag een nieuw wachtwoord aan.';
        return $result;
    }
    if (bkp_registration_find_pending_by_email($values['email'])) {
        $result['message'] = 'Voor dit e-mailadres staat al een aanvraag klaar voor beoordeling.';
        return $result;
    }

    $post_id = wp_insert_post(array(
        'post_type'   => 'bkp_registration',
        'post_status' => 'pending',
        'post_title'  => $values['name'],
    ), true);
    if (is_wp_error($post_id) || !$post_id) {
        $result['message'] = 'De aanvraag kon niet worden opgeslagen. Probeer het later opnieuw.';
        return $result;
    }

    update_post_meta($post_id, '_bkp_registration_status', 'pending');
    update_post_meta($post_id, '_bkp_registration_email', $values['email']);
    update_post_meta($post_id, '_bkp_registration_phone', $values['phone']);
    update_post_meta($post_id, '_bkp_registration_role', $values['role']);
    update_post_meta($post_id, '_bkp_registration_motivation', $values['motivation']);
    update_post_meta($post_id, '_bkp_registration_requested_at', current_time('mysql'));
    update_post_meta($post_id, '_bkp_registration_client_hash', bkp_registration_client_hash());

    $notification_email = sanitize_email((string) get_option('bkp_registration_notification_email', get_option('admin_email')));
    if ($notification_email && is_email($notification_email)) {
        $subject = 'Nieuwe registratieaanvraag jaarplanner: ' . $values['name'];
        $message = "Er is een nieuwe registratieaanvraag voor de Billekletsers Jaarplanner.\n\n";
        $message .= 'Naam: ' . $values['name'] . "\n";
        $message .= 'E-mail: ' . $values['email'] . "\n";
        if ($values['phone'] !== '') $message .= 'Telefoon: ' . $values['phone'] . "\n";
        if ($values['role'] !== '') $message .= 'Rol/commissie: ' . $values['role'] . "\n";
        if ($values['motivation'] !== '') $message .= "Toelichting: " . $values['motivation'] . "\n";
        $message .= "\nBeoordelen: " . admin_url('admin.php?page=bkp-registrations');
        wp_mail($notification_email, $subject, $message);
    }

    $result['success'] = true;
    $result['message'] = 'Je aanvraag is ontvangen. Na goedkeuring ontvang je per e-mail een link om je wachtwoord in te stellen.';
    $result['values'] = array('name' => '', 'email' => '', 'phone' => '', 'role' => '', 'motivation' => '');
    return $result;
}

function bkp_registration_render_public_page() {
    $result = bkp_registration_process_public_submission();
    $open = bkp_registration_is_open();
    $values = $result['values'];
    status_header(200);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Registreren voor de jaarplanner</title>
    <link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_uri()); ?>">
    <style>
        .bkp-registration-card{max-width:720px}.bkp-registration-intro{margin-bottom:20px}.bkp-registration-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.bkp-registration-grid label{display:grid;gap:6px;font-weight:800;text-align:left}.bkp-registration-grid input,.bkp-registration-grid textarea{box-sizing:border-box;width:100%;border:1px solid #d7c9cb;border-radius:10px;padding:12px 13px;background:#fff;color:#231f20;font:inherit}.bkp-registration-grid input:focus,.bkp-registration-grid textarea:focus{outline:3px solid rgba(210,8,25,.16);border-color:#d20819}.bkp-registration-span{grid-column:1/-1}.bkp-registration-consent{display:flex!important;grid-column:1/-1;grid-template-columns:22px 1fr!important;align-items:flex-start;gap:9px!important;font-weight:600!important}.bkp-registration-consent input{width:18px;height:18px;margin-top:2px}.bkp-registration-message{margin:0 0 18px;padding:12px 14px;border-radius:10px;font-weight:750}.bkp-registration-message.is-error{background:#fff0f1;color:#8b1119;border:1px solid #efb5ba}.bkp-registration-message.is-success{background:#edf9f0;color:#17642b;border:1px solid #a9d7b4}.bkp-registration-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:18px}.bkp-registration-actions .bkp-btn{border:0}.bkp-registration-back{font-weight:800;color:#8b1119}.bkp-reg-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important}@media(max-width:620px){.bkp-registration-grid{grid-template-columns:1fr}.bkp-registration-span{grid-column:auto}.bkp-registration-consent{grid-column:auto}}
    </style>
</head>
<body>
<main class="bkp-lock">
    <section class="bkp-lock-card bkp-registration-card">
        <img src="<?php echo esc_url(BKP_CORE_URI . '/assets/images/logo-cropped.jpg'); ?>" alt="C.V. De Billekletsers">
        <h1>Registreren als lid</h1>
        <p class="bkp-registration-intro"><?php echo esc_html((string) get_option('bkp_registration_intro', 'Vraag hier je persoonlijke account voor de interne jaarplanner aan. Een beheerder controleert de aanvraag voordat je kunt inloggen.')); ?></p>
        <?php if ($result['message'] !== ''): ?>
            <div class="bkp-registration-message <?php echo $result['success'] ? 'is-success' : 'is-error'; ?>"><?php echo esc_html($result['message']); ?></div>
        <?php endif; ?>
        <?php if (is_user_logged_in()): ?>
            <p>Je bent al ingelogd met een persoonlijk account.</p>
            <p><a class="bkp-btn" href="<?php echo esc_url(home_url('/')); ?>">Naar de jaarplanner</a></p>
        <?php elseif (!$open): ?>
            <p>Nieuwe registratieaanvragen zijn momenteel gesloten. Neem contact op met een beheerder.</p>
        <?php elseif (!$result['success']): ?>
            <form method="post" action="<?php echo esc_url(bkp_registration_url()); ?>">
                <?php wp_nonce_field('bkp_submit_registration', 'bkp_registration_nonce'); ?>
                <input type="hidden" name="bkp_registration_submit" value="1">
                <label class="bkp-reg-hp" aria-hidden="true">Website<input type="text" name="bkp_reg_website" tabindex="-1" autocomplete="off"></label>
                <div class="bkp-registration-grid">
                    <label>Naam *<input type="text" name="bkp_reg_name" value="<?php echo esc_attr($values['name']); ?>" autocomplete="name" required></label>
                    <label>E-mailadres *<input type="email" name="bkp_reg_email" value="<?php echo esc_attr($values['email']); ?>" autocomplete="email" required></label>
                    <label>Telefoonnummer<input type="tel" name="bkp_reg_phone" value="<?php echo esc_attr($values['phone']); ?>" autocomplete="tel"></label>
                    <label>Rol of commissie<input type="text" name="bkp_reg_role" value="<?php echo esc_attr($values['role']); ?>" placeholder="Bijvoorbeeld wagenbouwcommissie"></label>
                    <label class="bkp-registration-span">Toelichting<textarea name="bkp_reg_motivation" rows="4" placeholder="Eventueel: waarom heb je toegang nodig of voor welke onderdelen ben je actief?"><?php echo esc_textarea($values['motivation']); ?></textarea></label>
                    <label class="bkp-registration-consent"><input type="checkbox" name="bkp_reg_consent" value="1" required><span>Ik vraag een persoonlijk account aan voor de afgeschermde interne jaarplanner. Mijn gegevens worden gebruikt om mijn account en toegangsrechten te beheren.</span></label>
                </div>
                <div class="bkp-registration-actions"><button class="bkp-btn" type="submit">Aanvraag versturen</button><a class="bkp-registration-back" href="<?php echo esc_url(home_url('/')); ?>">Terug naar inloggen</a></div>
            </form>
        <?php else: ?>
            <p><a class="bkp-btn" href="<?php echo esc_url(home_url('/')); ?>">Terug naar inloggen</a></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
    <?php
    exit;
}

function bkp_registration_public_route() {
    if (is_admin() || wp_doing_ajax()) return;
    if (!isset($_GET['bkp_register'])) return;
    bkp_registration_render_public_page();
}
add_action('template_redirect', 'bkp_registration_public_route', -20);

function bkp_registration_unique_login($name, $email) {
    $email_part = strstr($email, '@', true);
    $base = sanitize_user((string) $email_part, true);
    if ($base === '') {
        $base = sanitize_user(str_replace(' ', '.', strtolower(remove_accents($name))), true);
    }
    if ($base === '') $base = 'lid';
    $login = $base;
    $number = 2;
    while (username_exists($login)) {
        $login = $base . $number;
        $number++;
    }
    return $login;
}

function bkp_registration_name_parts($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts) return array('', '');
    $first = array_shift($parts);
    return array($first, implode(' ', $parts));
}

function bkp_registration_assign_permissions($user_id, $sections) {
    $all = function_exists('bkp_frontend_sections') ? array_keys(bkp_frontend_sections()) : array();
    $clean = array_values(array_intersect($all, array_map('sanitize_key', (array) $sections)));
    $permissions = function_exists('bkp_frontend_permissions') ? bkp_frontend_permissions() : (array) get_option('bkp_frontend_permissions', array());
    if ($clean) $permissions[$user_id] = $clean;
    else unset($permissions[$user_id]);
    update_option('bkp_frontend_permissions', $permissions, false);
}

function bkp_registration_admin_actions() {
    if (!is_admin() || empty($_POST['bkp_registration_admin_action'])) return;
    if (!current_user_can('manage_options')) wp_die('Geen toegang.');

    $action = sanitize_key(wp_unslash($_POST['bkp_registration_admin_action']));
    if ($action === 'save_settings') {
        check_admin_referer('bkp_registration_settings');
        update_option('bkp_registration_open', !empty($_POST['registration_open']) ? '1' : '0', false);
        update_option('bkp_registration_intro', sanitize_textarea_field(wp_unslash($_POST['registration_intro'] ?? '')), false);
        $email = sanitize_email(wp_unslash($_POST['notification_email'] ?? ''));
        update_option('bkp_registration_notification_email', is_email($email) ? $email : get_option('admin_email'), false);
        if (function_exists('bkp_admin_set_notice')) bkp_admin_set_notice('success', 'De registratie-instellingen zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
    }

    $request_id = absint($_POST['request_id'] ?? 0);
    if (!$request_id || get_post_type($request_id) !== 'bkp_registration') wp_die('Ongeldige aanvraag.');
    check_admin_referer('bkp_registration_request_' . $request_id);

    if ($action === 'approve') {
        $email = sanitize_email((string) get_post_meta($request_id, '_bkp_registration_email', true));
        $name  = sanitize_text_field((string) get_the_title($request_id));
        if (!$email || !is_email($email)) {
            if (function_exists('bkp_admin_set_notice')) bkp_admin_set_notice('error', 'De aanvraag heeft geen geldig e-mailadres.');
            wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
        }

        $user_id = email_exists($email);
        $created = false;
        if (!$user_id) {
            list($first_name, $last_name) = bkp_registration_name_parts($name);
            $user_id = wp_insert_user(array(
                'user_login'   => bkp_registration_unique_login($name, $email),
                'user_email'   => $email,
                'display_name' => $name,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'user_pass'    => wp_generate_password(32, true, true),
                'role'         => 'subscriber',
            ));
            if (is_wp_error($user_id)) {
                if (function_exists('bkp_admin_set_notice')) bkp_admin_set_notice('error', 'Account aanmaken mislukt: ' . $user_id->get_error_message());
                wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
            }
            $created = true;
        }

        bkp_registration_assign_permissions((int) $user_id, isset($_POST['sections']) ? (array) wp_unslash($_POST['sections']) : array());
        update_post_meta($request_id, '_bkp_registration_status', 'approved');
        update_post_meta($request_id, '_bkp_registration_user_id', (int) $user_id);
        update_post_meta($request_id, '_bkp_registration_processed_at', current_time('mysql'));
        update_post_meta($request_id, '_bkp_registration_processed_by', get_current_user_id());
        wp_update_post(array('ID' => $request_id, 'post_status' => 'private'));

        if ($created) {
            wp_new_user_notification((int) $user_id, null, 'user');
        } else {
            wp_mail($email, 'Toegang tot de Billekletsers Jaarplanner goedgekeurd', "Je aanvraag is goedgekeurd. Er bestond al een WordPress-account voor dit e-mailadres.\n\nInloggen: " . wp_login_url(home_url('/')));
        }

        if (function_exists('bkp_admin_set_notice')) {
            $message = $created ? 'De aanvraag is goedgekeurd. Het account is aangemaakt en de gebruiker ontvangt een e-mail om een wachtwoord in te stellen.' : 'De aanvraag is gekoppeld aan het bestaande account en goedgekeurd.';
            bkp_admin_set_notice('success', $message);
        }
        wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
    }

    if ($action === 'reject') {
        update_post_meta($request_id, '_bkp_registration_status', 'rejected');
        update_post_meta($request_id, '_bkp_registration_processed_at', current_time('mysql'));
        update_post_meta($request_id, '_bkp_registration_processed_by', get_current_user_id());
        wp_update_post(array('ID' => $request_id, 'post_status' => 'private'));
        $email = sanitize_email((string) get_post_meta($request_id, '_bkp_registration_email', true));
        if ($email && is_email($email) && !empty($_POST['send_rejection_email'])) {
            wp_mail($email, 'Registratieaanvraag Billekletsers Jaarplanner', "Je aanvraag voor een persoonlijk account is niet goedgekeurd. Neem bij vragen contact op met het bestuur of de beheerder van de jaarplanner.");
        }
        if (function_exists('bkp_admin_set_notice')) bkp_admin_set_notice('success', 'De aanvraag is afgewezen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
    }

    if ($action === 'delete') {
        wp_delete_post($request_id, true);
        if (function_exists('bkp_admin_set_notice')) bkp_admin_set_notice('success', 'De registratieaanvraag is verwijderd.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-registrations')); exit;
    }
}
add_action('admin_init', 'bkp_registration_admin_actions', 5);

function bkp_registration_query($status) {
    return get_posts(array(
        'post_type'      => 'bkp_registration',
        'post_status'    => array('pending', 'private', 'draft', 'publish'),
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(array('key' => '_bkp_registration_status', 'value' => $status)),
    ));
}

function bkp_registration_admin_page() {
    if (!current_user_can('manage_options')) return;
    $pending = bkp_registration_query('pending');
    $processed = array_merge(bkp_registration_query('approved'), bkp_registration_query('rejected'));
    usort($processed, function($a, $b) { return strcmp($b->post_date, $a->post_date); });
    $sections = function_exists('bkp_frontend_sections') ? bkp_frontend_sections() : array();
    $url = bkp_registration_url();
    ?>
    <div class="wrap bkp-admin-wrap bkp-registration-admin">
        <h1>Registratieaanvragen</h1>
        <?php if (function_exists('bkp_admin_show_notice')) bkp_admin_show_notice(); ?>
        <p class="bkp-admin-lead">Deel de registratielink met leden (werkend of niet). Een aanvraag geeft nog geen toegang. Pas na jouw goedkeuring wordt een persoonlijk WordPress-account aangemaakt.</p>

        <section class="bkp-admin-panel bkp-registration-share">
            <h2>Registratielink delen</h2>
            <div class="bkp-registration-copy-row"><input id="bkp-registration-url" class="large-text code" readonly value="<?php echo esc_attr($url); ?>"><button type="button" class="button button-primary" data-bkp-copy-registration>Kopieer link</button><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($url); ?>">Formulier bekijken</a></div>
            <p class="description">Deze link mag rechtstreeks via WhatsApp, e-mail of Spond worden gedeeld. Het formulier is bereikbaar zonder het algemene kijkwachtwoord.</p>
        </section>

        <section class="bkp-admin-panel">
            <h2>Instellingen</h2>
            <form method="post">
                <?php wp_nonce_field('bkp_registration_settings'); ?>
                <input type="hidden" name="bkp_registration_admin_action" value="save_settings">
                <table class="form-table">
                    <tr><th>Nieuwe aanvragen</th><td><label><input type="checkbox" name="registration_open" value="1" <?php checked(bkp_registration_is_open()); ?>> Registratieformulier openstellen</label></td></tr>
                    <tr><th><label for="notification_email">Melding naar</label></th><td><input class="regular-text" type="email" id="notification_email" name="notification_email" value="<?php echo esc_attr((string) get_option('bkp_registration_notification_email', get_option('admin_email'))); ?>"><p class="description">Dit adres ontvangt een melding bij iedere nieuwe aanvraag.</p></td></tr>
                    <tr><th><label for="registration_intro">Introtekst formulier</label></th><td><textarea class="large-text" rows="3" id="registration_intro" name="registration_intro"><?php echo esc_textarea((string) get_option('bkp_registration_intro', 'Vraag hier je persoonlijke account voor de interne jaarplanner aan. Een beheerder controleert de aanvraag voordat je kunt inloggen.')); ?></textarea></td></tr>
                </table>
                <?php submit_button('Registratie-instellingen opslaan'); ?>
            </form>
        </section>

        <h2 class="bkp-registration-section-title">Openstaande aanvragen <span class="bkp-registration-count"><?php echo (int) count($pending); ?></span></h2>
        <?php if (!$pending): ?>
            <div class="notice notice-info inline"><p>Er zijn momenteel geen openstaande registratieaanvragen.</p></div>
        <?php else: ?>
            <div class="bkp-registration-request-list">
            <?php foreach ($pending as $request):
                $id = $request->ID;
                $email = (string) get_post_meta($id, '_bkp_registration_email', true);
                $phone = (string) get_post_meta($id, '_bkp_registration_phone', true);
                $role = (string) get_post_meta($id, '_bkp_registration_role', true);
                $motivation = (string) get_post_meta($id, '_bkp_registration_motivation', true);
                $requested = (string) get_post_meta($id, '_bkp_registration_requested_at', true);
            ?>
                <article class="bkp-admin-panel bkp-registration-request">
                    <div class="bkp-registration-request-head"><div><h3><?php echo esc_html($request->post_title); ?></h3><p><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a><?php echo $phone !== '' ? ' · ' . esc_html($phone) : ''; ?></p></div><span><?php echo esc_html($requested ? mysql2date('j F Y H:i', $requested) : get_the_date('j F Y H:i', $request)); ?></span></div>
                    <?php if ($role !== '' || $motivation !== ''): ?><div class="bkp-registration-request-info"><?php if ($role !== ''): ?><p><strong>Rol/commissie:</strong> <?php echo esc_html($role); ?></p><?php endif; ?><?php if ($motivation !== ''): ?><p><strong>Toelichting:</strong><br><?php echo nl2br(esc_html($motivation)); ?></p><?php endif; ?></div><?php endif; ?>
                    <form method="post" class="bkp-registration-approval-form">
                        <?php wp_nonce_field('bkp_registration_request_' . $id); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int) $id; ?>">
                        <div class="bkp-registration-permissions"><strong>Bewerkrechten na goedkeuring</strong><p class="description">Zonder vinkjes kan het lid alles bekijken, maar niets wijzigen.</p><div class="bkp-registration-permission-grid"><?php foreach ($sections as $key => $section): ?><label><input type="checkbox" name="sections[]" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($section['label']); ?></label><?php endforeach; ?></div></div>
                        <div class="bkp-registration-request-actions"><button class="button button-primary" name="bkp_registration_admin_action" value="approve">Goedkeuren en account maken</button><label><input type="checkbox" name="send_rejection_email" value="1" checked> mail bij afwijzen</label><button class="button" name="bkp_registration_admin_action" value="reject" onclick="return confirm('Deze aanvraag afwijzen?');">Afwijzen</button></div>
                    </form>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <details class="bkp-registration-history">
            <summary>Afgehandelde aanvragen (<?php echo (int) count($processed); ?>)</summary>
            <?php if (!$processed): ?><p>Er zijn nog geen afgehandelde aanvragen.</p><?php else: ?><div class="bkp-registration-history-list"><?php foreach ($processed as $request):
                $id = $request->ID; $status = bkp_registration_status($id); $email = (string) get_post_meta($id, '_bkp_registration_email', true); $user_id = absint(get_post_meta($id, '_bkp_registration_user_id', true));
                ?><div class="bkp-registration-history-row"><div><strong><?php echo esc_html($request->post_title); ?></strong><span><?php echo esc_html($email); ?> · <?php echo $status === 'approved' ? 'goedgekeurd' : 'afgewezen'; ?><?php if ($user_id && get_userdata($user_id)) echo ' · account: ' . esc_html(get_userdata($user_id)->user_login); ?></span></div><form method="post"><?php wp_nonce_field('bkp_registration_request_' . $id); ?><input type="hidden" name="request_id" value="<?php echo (int) $id; ?>"><button class="button-link-delete" name="bkp_registration_admin_action" value="delete" onclick="return confirm('Deze registratiehistorie definitief verwijderen?');">Verwijderen</button></form></div>
            <?php endforeach; ?></div><?php endif; ?>
        </details>
    </div>
    <script>
    document.addEventListener('click',function(event){
        var button=event.target.closest('[data-bkp-copy-registration]');
        if(!button)return;
        var input=document.getElementById('bkp-registration-url');
        if(!input)return;
        var done=function(){button.textContent='Gekopieerd';setTimeout(function(){button.textContent='Kopieer link';},1800);};
        if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(input.value).then(done);}
        else{input.focus();input.select();document.execCommand('copy');done();}
    });
    </script>
    <?php
}
