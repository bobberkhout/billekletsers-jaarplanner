<?php
if (!defined('ABSPATH')) { exit; }

const BKP_INVENTORY_RESPONSE_SCHEMA = '1.0.0';

function bkp_inventory_response_table() {
    global $wpdb;
    return $wpdb->prefix . 'bkp_inventory_responses';
}

function bkp_inventory_install_table() {
    global $wpdb;
    $table = bkp_inventory_response_table();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        inventory_id bigint(20) unsigned NOT NULL,
        season varchar(32) NOT NULL DEFAULT '',
        name varchar(190) NOT NULL,
        name_key varchar(64) NOT NULL,
        quantity int(10) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY inventory_season_name (inventory_id, season, name_key),
        KEY inventory_season (inventory_id, season),
        KEY user_id (user_id)
    ) {$charset_collate};";
    dbDelta($sql);
    update_option('bkp_inventory_response_schema', BKP_INVENTORY_RESPONSE_SCHEMA, false);
}

function bkp_inventory_maybe_install_table() {
    $version = (string) get_option('bkp_inventory_response_schema', '0');
    if (version_compare($version, BKP_INVENTORY_RESPONSE_SCHEMA, '>=')) return;
    bkp_inventory_install_table();
}
add_action('init', 'bkp_inventory_maybe_install_table', 2);

function bkp_inventory_current_season() {
    $season = sanitize_text_field((string) get_option('bkp_season', '')); 
    return $season !== '' ? $season : 'onbekend';
}

function bkp_inventory_name_key($name) {
    $name = trim(preg_replace('/\s+/u', ' ', remove_accents((string) $name)));
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return hash('sha256', $name);
}

function bkp_inventory_is_open($inventory) {
    $post = $inventory instanceof WP_Post ? $inventory : get_post(absint($inventory));
    if (!$post || $post->post_type !== 'bkp_inventory' || $post->post_status !== 'publish') return false;
    if ((string) get_post_meta($post->ID, '_bkp_inventory_open', true) !== '1') return false;
    $due = (string) get_post_meta($post->ID, '_bkp_due_date', true);
    return $due === '' || $due >= wp_date('Y-m-d');
}

function bkp_inventory_status_label($inventory) {
    $post = $inventory instanceof WP_Post ? $inventory : get_post(absint($inventory));
    if (!$post) return 'Gesloten';
    if (bkp_inventory_is_open($post)) return 'Open';
    $due = (string) get_post_meta($post->ID, '_bkp_due_date', true);
    if ($due !== '' && $due < wp_date('Y-m-d')) return 'Sluitdatum verstreken';
    return 'Gesloten';
}

function bkp_inventory_response_rows($inventory_id, $season = '') {
    global $wpdb;
    $inventory_id = absint($inventory_id);
    $season = $season !== '' ? sanitize_text_field($season) : bkp_inventory_current_season();
    if (!$inventory_id) return array();
    $table = bkp_inventory_response_table();
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE inventory_id = %d AND season = %s ORDER BY name ASC, id ASC",
        $inventory_id,
        $season
    ));
}

function bkp_inventory_response_stats($inventory_id, $season = '') {
    global $wpdb;
    $inventory_id = absint($inventory_id);
    $season = $season !== '' ? sanitize_text_field($season) : bkp_inventory_current_season();
    if (!$inventory_id) return array('count'=>0, 'total'=>0);
    $table = bkp_inventory_response_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS response_count, COALESCE(SUM(quantity), 0) AS quantity_total FROM {$table} WHERE inventory_id = %d AND season = %s",
        $inventory_id,
        $season
    ));
    return array(
        'count' => (int) ($row->response_count ?? 0),
        'total' => (int) ($row->quantity_total ?? 0),
    );
}

function bkp_inventory_response_count_for_season($season = '') {
    global $wpdb;
    $season = $season !== '' ? sanitize_text_field($season) : bkp_inventory_current_season();
    $table = bkp_inventory_response_table();
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE season = %s", $season));
}

function bkp_inventory_upsert_response($inventory_id, $name, $quantity, $user_id = 0, $season = '') {
    global $wpdb;
    $inventory_id = absint($inventory_id);
    $post = get_post($inventory_id);
    if (!$post || $post->post_type !== 'bkp_inventory') return new WP_Error('invalid_inventory', 'De inventarisatie bestaat niet.');

    $name = sanitize_text_field($name);
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length < 2 || $length > 120) return new WP_Error('invalid_name', 'Vul een geldige naam in.');

    $quantity = intval($quantity);
    if ($quantity < 0 || $quantity > 100000) return new WP_Error('invalid_quantity', 'Vul een aantal tussen 0 en 100.000 in.');

    $season = $season !== '' ? sanitize_text_field($season) : bkp_inventory_current_season();
    $table = bkp_inventory_response_table();
    $now = current_time('mysql');
    $sql = $wpdb->prepare(
        "INSERT INTO {$table} (inventory_id, season, name, name_key, quantity, user_id, created_at, updated_at)
         VALUES (%d, %s, %s, %s, %d, %d, %s, %s)
         ON DUPLICATE KEY UPDATE name = VALUES(name), quantity = VALUES(quantity), user_id = VALUES(user_id), updated_at = VALUES(updated_at)",
        $inventory_id,
        $season,
        $name,
        bkp_inventory_name_key($name),
        $quantity,
        absint($user_id),
        $now,
        $now
    );
    $result = $wpdb->query($sql);
    if ($result === false) return new WP_Error('database_error', 'Het antwoord kon niet worden opgeslagen.');
    return true;
}

function bkp_inventory_existing_response_for_name($inventory_id, $name, $season = '') {
    global $wpdb;
    $inventory_id = absint($inventory_id);
    $name = trim((string) $name);
    if (!$inventory_id || $name === '') return null;
    $season = $season !== '' ? sanitize_text_field($season) : bkp_inventory_current_season();
    $table = bkp_inventory_response_table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE inventory_id = %d AND season = %s AND name_key = %s LIMIT 1",
        $inventory_id,
        $season,
        bkp_inventory_name_key($name)
    ));
}

function bkp_inventory_redirect_url($inventory_id, $notice = '') {
    $url = home_url('/');
    if ($notice !== '') $url = add_query_arg(array('bkp_inventory_notice'=>$notice, 'inventory_id'=>absint($inventory_id)), $url);
    return $url . '#inventory-' . absint($inventory_id);
}

function bkp_inventory_submit_handler() {
    $inventory_id = absint($_POST['inventory_id'] ?? 0);
    if (!bkp_has_access()) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    if (!$inventory_id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkp_inventory_nonce'] ?? '')), 'bkp_inventory_submit_' . $inventory_id)) {
        wp_safe_redirect(bkp_inventory_redirect_url($inventory_id, 'error'));
        exit;
    }
    if (!empty($_POST['website'])) {
        wp_safe_redirect(bkp_inventory_redirect_url($inventory_id, 'saved'));
        exit;
    }
    $post = get_post($inventory_id);
    if (!$post || !bkp_inventory_is_open($post)) {
        wp_safe_redirect(bkp_inventory_redirect_url($inventory_id, 'closed'));
        exit;
    }

    $rate_key = 'bkp_inventory_rate_' . substr(hash('sha256', bkp_client_key() . '|' . $inventory_id), 0, 32);
    $attempts = (int) get_transient($rate_key);
    if ($attempts >= 12) {
        wp_safe_redirect(bkp_inventory_redirect_url($inventory_id, 'rate'));
        exit;
    }
    set_transient($rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);

    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $quantity = isset($_POST['quantity']) ? intval(wp_unslash($_POST['quantity'])) : -1;
    $result = bkp_inventory_upsert_response($inventory_id, $name, $quantity, get_current_user_id());
    wp_safe_redirect(bkp_inventory_redirect_url($inventory_id, is_wp_error($result) ? 'error' : 'saved'));
    exit;
}
add_action('admin_post_bkp_inventory_submit', 'bkp_inventory_submit_handler');
add_action('admin_post_nopriv_bkp_inventory_submit', 'bkp_inventory_submit_handler');

function bkp_inventory_admin_menu() {
    add_submenu_page(null, 'Inventarisatieresultaten', 'Inventarisatieresultaten', 'edit_posts', 'bkp-inventory-results', 'bkp_inventory_admin_results_page');
}
add_action('admin_menu', 'bkp_inventory_admin_menu', 20);

function bkp_inventory_results_url($inventory_id) {
    return add_query_arg(array('page'=>'bkp-inventory-results', 'inventory_id'=>absint($inventory_id)), admin_url('admin.php'));
}

function bkp_inventory_export_url($inventory_id) {
    return wp_nonce_url(
        add_query_arg(array('action'=>'bkp_inventory_export', 'inventory_id'=>absint($inventory_id)), admin_url('admin-post.php')),
        'bkp_inventory_export_' . absint($inventory_id)
    );
}

function bkp_inventory_render_admin_summary_cell($inventory_id) {
    $inventory_id = absint($inventory_id);
    if (!$inventory_id) {
        echo '<span class="description">Na opslaan beschikbaar</span>';
        return;
    }
    $stats = bkp_inventory_response_stats($inventory_id);
    echo '<div class="bkp-inventory-admin-summary"><strong>'.esc_html(number_format_i18n($stats['total'])).' totaal</strong><span>'.esc_html(number_format_i18n($stats['count'])).' naam/namen</span><a href="'.esc_url(bkp_inventory_results_url($inventory_id)).'">Bekijken</a><a href="'.esc_url(bkp_inventory_export_url($inventory_id)).'">Excel</a></div>';
}

function bkp_inventory_admin_handle_results() {
    if (!is_admin() || empty($_POST['bkp_inventory_admin_action'])) return;
    if (!current_user_can('edit_posts')) wp_die('Geen toegang.');
    $action = sanitize_key(wp_unslash($_POST['bkp_inventory_admin_action']));
    if ($action !== 'save_results') return;

    $inventory_id = absint($_POST['inventory_id'] ?? 0);
    $post = get_post($inventory_id);
    if (!$post || $post->post_type !== 'bkp_inventory') wp_die('Inventarisatie niet gevonden.');
    check_admin_referer('bkp_inventory_results_' . $inventory_id);

    global $wpdb;
    $table = bkp_inventory_response_table();
    $season = bkp_inventory_current_season();
    $rows = isset($_POST['rows']) ? (array) wp_unslash($_POST['rows']) : array();
    foreach ($rows as $row_id=>$row) {
        $row_id = absint($row_id);
        if (!$row_id || !is_array($row)) continue;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND inventory_id = %d AND season = %s",
            $row_id,
            $inventory_id,
            $season
        ));
        if (!$existing) continue;
        if (!empty($row['delete'])) {
            $wpdb->delete($table, array('id'=>$row_id), array('%d'));
            continue;
        }
        $name = sanitize_text_field($row['name'] ?? '');
        $quantity = intval($row['quantity'] ?? 0);
        if ($name === '' || $quantity < 0 || $quantity > 100000) continue;
        $wpdb->update(
            $table,
            array('name'=>$name, 'name_key'=>bkp_inventory_name_key($name), 'quantity'=>$quantity, 'updated_at'=>current_time('mysql')),
            array('id'=>$row_id),
            array('%s','%s','%d','%s'),
            array('%d')
        );
    }

    $new_name = sanitize_text_field(wp_unslash($_POST['new_name'] ?? ''));
    $new_quantity = isset($_POST['new_quantity']) ? intval(wp_unslash($_POST['new_quantity'])) : 0;
    if ($new_name !== '') bkp_inventory_upsert_response($inventory_id, $new_name, $new_quantity, get_current_user_id(), $season);

    bkp_admin_set_notice('success', 'De inventarisatieresultaten zijn opgeslagen.');
    wp_safe_redirect(bkp_inventory_results_url($inventory_id));
    exit;
}
add_action('admin_init', 'bkp_inventory_admin_handle_results', 14);

function bkp_inventory_admin_results_page() {
    if (!current_user_can('edit_posts')) return;
    $inventory_id = absint($_GET['inventory_id'] ?? 0);
    $inventory = get_post($inventory_id);
    if (!$inventory || $inventory->post_type !== 'bkp_inventory') {
        echo '<div class="wrap"><h1>Inventarisatie niet gevonden</h1><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bkp-inventories')).'">Terug naar inventarisaties</a></p></div>';
        return;
    }
    $season = bkp_inventory_current_season();
    $rows = bkp_inventory_response_rows($inventory_id, $season);
    $stats = bkp_inventory_response_stats($inventory_id, $season);
    echo '<div class="wrap bkp-admin-wrap"><h1>Resultaten: '.esc_html($inventory->post_title).'</h1>';
    bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Reacties voor seizoen <strong>'.esc_html($season).'</strong>. Dezelfde naam opnieuw insturen werkt het bestaande aantal bij.</p>';
    echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=bkp-inventories')).'">Terug naar inventarisaties</a> <a class="button button-primary" href="'.esc_url(bkp_inventory_export_url($inventory_id)).'">Exporteren naar Excel</a></p>';
    echo '<div class="bkp-admin-cards bkp-inventory-result-cards"><div class="bkp-admin-card"><strong>'.esc_html(number_format_i18n($stats['total'])).'</strong><span>Totaal aantal</span></div><div class="bkp-admin-card"><strong>'.esc_html(number_format_i18n($stats['count'])).'</strong><span>Aantal namen</span></div><div class="bkp-admin-card"><strong>'.esc_html(bkp_inventory_status_label($inventory)).'</strong><span>Reactiestatus</span></div></div>';

    echo '<form method="post" class="bkp-grid-form">';
    wp_nonce_field('bkp_inventory_results_' . $inventory_id);
    echo '<input type="hidden" name="bkp_inventory_admin_action" value="save_results"><input type="hidden" name="inventory_id" value="'.esc_attr($inventory_id).'">';
    echo '<section class="bkp-admin-panel"><h2>Reactie toevoegen</h2><div class="bkp-inventory-quick-add"><label>Naam<input type="text" name="new_name" placeholder="Voor- en achternaam"></label><label>Aantal<input type="number" min="0" max="100000" step="1" name="new_quantity" value="1"></label></div></section>';
    echo '<div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid"><thead><tr><th>Naam</th><th>Aantal</th><th>Ingediend</th><th>Bijgewerkt</th><th>Account</th><th>Verwijderen</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $user = $row->user_id ? get_userdata((int) $row->user_id) : null;
        echo '<tr><td><input type="text" name="rows['.esc_attr($row->id).'][name]" value="'.esc_attr($row->name).'" required></td><td><input type="number" min="0" max="100000" step="1" name="rows['.esc_attr($row->id).'][quantity]" value="'.esc_attr($row->quantity).'" required></td><td>'.esc_html(mysql2date('d-m-Y H:i', $row->created_at)).'</td><td>'.esc_html(mysql2date('d-m-Y H:i', $row->updated_at)).'</td><td>'.esc_html($user ? $user->display_name : 'Algemeen wachtwoord').'</td><td><label><input type="checkbox" name="rows['.esc_attr($row->id).'][delete]" value="1"> verwijderen</label></td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="6">Er zijn voor dit seizoen nog geen reacties.</td></tr>';
    echo '</tbody></table></div><div class="bkp-save-bar"><span>Wijzigingen worden pas toegepast na opslaan.</span><button type="submit" class="button button-primary">Resultaten opslaan</button></div></form></div>';
}

function bkp_inventory_xlsx_col($number) {
    $letters = '';
    while ($number > 0) {
        $number--;
        $letters = chr(65 + ($number % 26)) . $letters;
        $number = intdiv($number, 26);
    }
    return $letters;
}

function bkp_inventory_xlsx_escape($value) {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function bkp_inventory_xlsx_sheet($rows) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_values($rows) as $r_index=>$row) {
        $row_number = $r_index + 1;
        $xml .= '<row r="'.$row_number.'">';
        foreach (array_values($row) as $c_index=>$value) {
            $ref = bkp_inventory_xlsx_col($c_index + 1) . $row_number;
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$ref.'" t="n"><v>'.bkp_inventory_xlsx_escape($value).'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.bkp_inventory_xlsx_escape($value).'</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function bkp_inventory_export_handler() {
    $inventory_id = absint($_GET['inventory_id'] ?? 0);
    if (!current_user_can('edit_posts') && !bkp_user_can_frontend_edit('inventories')) wp_die('Geen toegang.');
    check_admin_referer('bkp_inventory_export_' . $inventory_id);
    $inventory = get_post($inventory_id);
    if (!$inventory || $inventory->post_type !== 'bkp_inventory') wp_die('Inventarisatie niet gevonden.');

    $season = bkp_inventory_current_season();
    $responses = bkp_inventory_response_rows($inventory_id, $season);
    $stats = bkp_inventory_response_stats($inventory_id, $season);
    $rows = array(
        array('Inventarisatie', $inventory->post_title),
        array('Seizoen', $season),
        array('Totaal aantal', $stats['total']),
        array('Aantal namen', $stats['count']),
        array(),
        array('Naam', 'Aantal', 'Ingediend', 'Bijgewerkt', 'Account'),
    );
    foreach ($responses as $response) {
        $user = $response->user_id ? get_userdata((int) $response->user_id) : null;
        $rows[] = array(
            $response->name,
            (int) $response->quantity,
            mysql2date('d-m-Y H:i', $response->created_at),
            mysql2date('d-m-Y H:i', $response->updated_at),
            $user ? $user->display_name : 'Algemeen wachtwoord',
        );
    }

    $base = sanitize_file_name($inventory->post_title . '-' . $season);
    if (class_exists('ZipArchive')) {
        $tmp = wp_tempnam($base . '.xlsx');
        $zip = new ZipArchive();
        if ($tmp && $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventarisatie" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', bkp_inventory_xlsx_sheet($rows));
            $zip->close();
            nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="inventarisatie-' . $base . '.xlsx"');
            header('Content-Length: ' . filesize($tmp));
            while (ob_get_level()) ob_end_clean();
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventarisatie-' . $base . '.csv"');
    while (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) bkp_csv_row($out, $row);
    fclose($out);
    exit;
}
add_action('admin_post_bkp_inventory_export', 'bkp_inventory_export_handler');

function bkp_inventory_frontend_response_list($inventory_id) {
    if (!is_user_logged_in()) return;
    $responses = bkp_inventory_response_rows($inventory_id);
    $stats = bkp_inventory_response_stats($inventory_id);
    echo '<section class="bkp-inventory-participants" aria-label="Ingeschreven namen">';
    echo '<div class="bkp-inventory-participants-head"><div><strong>Wie gaan mee?</strong><span>'.esc_html(number_format_i18n($stats['count'])).' inschrijving(en)</span></div><b>'.esc_html(number_format_i18n($stats['total'])).' totaal</b></div>';
    if (!$responses) {
        echo '<p class="bkp-inventory-no-participants">Er zijn nog geen namen aangemeld.</p>';
    } else {
        echo '<ul class="bkp-inventory-participant-list">';
        foreach ($responses as $response) {
            echo '<li><span>'.esc_html($response->name).'</span><strong>'.esc_html(number_format_i18n((int) $response->quantity)).'</strong></li>';
        }
        echo '</ul>';
    }
    echo '<p class="bkp-inventory-privacy-note">Deze lijst is alleen zichtbaar voor leden die met een persoonlijk account zijn ingelogd.</p>';
    echo '</section>';
}

function bkp_inventory_front_notice($inventory_id) {
    if (absint($_GET['inventory_id'] ?? 0) !== absint($inventory_id)) return;
    $notice = sanitize_key(wp_unslash($_GET['bkp_inventory_notice'] ?? ''));
    $messages = array(
        'saved' => array('success', 'Bedankt. Je antwoord is opgeslagen. Dezelfde naam opnieuw insturen past het aantal aan.'),
        'closed' => array('error', 'Deze inventarisatie is gesloten.'),
        'rate' => array('error', 'Er zijn te veel antwoorden kort na elkaar verstuurd. Probeer het over enkele minuten opnieuw.'),
        'error' => array('error', 'Het antwoord kon niet worden opgeslagen. Controleer de naam en het aantal.'),
    );
    if (empty($messages[$notice])) return;
    echo '<div class="bkp-inventory-notice is-'.esc_attr($messages[$notice][0]).'">'.esc_html($messages[$notice][1]).'</div>';
}

function bkp_render_inventory_frontend($inventories) {
    if (!$inventories) {
        echo '<div class="bkp-empty">Er zijn nog geen inventarisaties aangemaakt.</div>';
        return;
    }
    echo '<div class="bkp-inventory-grid">';
    foreach ($inventories as $inventory) {
        $due = (string) get_post_meta($inventory->ID, '_bkp_due_date', true);
        $period = trim((string) get_post_meta($inventory->ID, '_bkp_period', true));
        $responsible = trim((string) get_post_meta($inventory->ID, '_bkp_responsible', true));
        $notes = trim((string) get_post_meta($inventory->ID, '_bkp_notes', true));
        $open = bkp_inventory_is_open($inventory);
        $user = wp_get_current_user();
        $default_name = is_user_logged_in() ? $user->display_name : '';
        $existing = $default_name !== '' ? bkp_inventory_existing_response_for_name($inventory->ID, $default_name) : null;
        $project_name = bkp_project_name(bkp_get_project_id($inventory->ID), 'Niet gekoppeld');
        echo '<article class="bkp-card bkp-inventory-card" id="inventory-'.esc_attr($inventory->ID).'">';
        echo '<div class="bkp-inventory-card-head"><div><span class="bkp-eyebrow bkp-eyebrow--red">'.esc_html($project_name).'</span><h3>'.esc_html($inventory->post_title).'</h3></div><span class="bkp-badge '.($open?'bkp-badge--yes':'bkp-badge--open').'">'.esc_html(bkp_inventory_status_label($inventory)).'</span></div>';
        echo '<dl class="bkp-inventory-meta">';
        if ($period !== '') echo '<div><dt>Periode</dt><dd>'.esc_html($period).'</dd></div>';
        echo '<div><dt>Sluitdatum</dt><dd>'.esc_html($due !== '' ? bkp_format_date($due) : 'Niet ingevuld').'</dd></div>';
        if ($responsible !== '') echo '<div><dt>Verantwoordelijke</dt><dd>'.esc_html($responsible).'</dd></div>';
        echo '</dl>';
        if ($notes !== '') echo '<p>'.nl2br(esc_html($notes)).'</p>';
        bkp_inventory_front_notice($inventory->ID);
        if ($open) {
            echo '<form class="bkp-inventory-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bkp_inventory_submit"><input type="hidden" name="inventory_id" value="'.esc_attr($inventory->ID).'">';
            wp_nonce_field('bkp_inventory_submit_' . $inventory->ID, 'bkp_inventory_nonce');
            echo '<div class="bkp-inventory-fields"><label><span>Naam</span><input class="bkp-field" type="text" name="name" maxlength="120" value="'.esc_attr($default_name).'" required autocomplete="name"></label><label><span>Aantal</span><input class="bkp-field" type="number" name="quantity" min="0" max="100000" step="1" value="'.esc_attr($existing ? $existing->quantity : 1).'" required inputmode="numeric"></label></div>';
            echo '<label class="bkp-honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>';
            echo '<div class="bkp-inventory-submit"><button class="bkp-btn" type="submit">Antwoord opslaan</button><small>Gebruik dezelfde naam opnieuw om het aantal aan te passen.</small></div></form>';
        } else {
            echo '<div class="bkp-inventory-closed">Deze inventarisatie neemt momenteel geen reacties aan.</div>';
        }
        bkp_inventory_frontend_response_list($inventory->ID);
        echo '</article>';
    }
    echo '</div>';
}

function bkp_inventory_delete_responses_with_inventory($post_id) {
    if (get_post_type($post_id) !== 'bkp_inventory') return;
    global $wpdb;
    $wpdb->delete(bkp_inventory_response_table(), array('inventory_id'=>absint($post_id)), array('%d'));
}
add_action('before_delete_post', 'bkp_inventory_delete_responses_with_inventory');
