<?php
if (!defined('ABSPATH')) { exit; }

function bkp_assets() {
    wp_enqueue_style('bkp-core-frontend', BKP_CORE_URI . '/assets/css/frontend.css', array(), BKP_CORE_VERSION);
    wp_enqueue_script('bkp-planning', BKP_CORE_URI . '/assets/js/planning.js', array(), BKP_CORE_VERSION, true);
    if (isset($_GET['bkp_edit'])) {
        wp_enqueue_script('bkp-frontend-editor', BKP_CORE_URI . '/assets/js/frontend-editor.js', array(), BKP_CORE_VERSION, true);
    }
}
add_action('wp_enqueue_scripts', 'bkp_assets');

function bkp_month_definitions() {
    $stored = get_option('bkp_months', array());
    $out = array();
    if (is_array($stored)) {
        foreach ($stored as $month) {
            $key = sanitize_text_field((string) ($month['key'] ?? ''));
            $label = trim((string) ($month['label'] ?? ''));
            $year = absint($month['year'] ?? 0);
            if ($key && $label) { $out[$key] = trim($label . ' ' . ($year ?: '')); }
        }
    }
    if ($out) { return $out; }
    return array(
        '2026-03'=>'maart 2026','2026-04'=>'april 2026','2026-05'=>'mei 2026','2026-06'=>'juni 2026',
        '2026-07'=>'juli 2026','2026-08'=>'augustus 2026','2026-09'=>'september 2026','2026-10'=>'oktober 2026',
        '2026-11'=>'november 2026','2026-12'=>'december 2026','2027-01'=>'januari 2027','2027-02'=>'februari 2027'
    );
}


/**
 * Vaste statuslijsten voor alle planningonderdelen.
 * Bestaande afwijkende waarden blijven als legacy-keuze zichtbaar totdat
 * een beheerder of verantwoordelijke bewust een standaardstatus kiest.
 */
function bkp_status_definitions() {
    return array(
        'projects' => array(
            'default' => 'Voorbereiding',
            'options' => array('Voorbereiding','Actief','Wacht op actie','Afgerond','Geannuleerd','Gearchiveerd'),
        ),
        'project' => array(
            'default' => 'Nog in te plannen',
            'options' => array('Nog in te plannen','Gepland','Bezig','Wacht op reactie','Afgerond'),
        ),
        'inventories' => array(
            'default' => 'Voorbereiding',
            'options' => array('Voorbereiding','Open','Gesloten','Afgerond'),
        ),
        'todos' => array(
            'default' => 'Open',
            'options' => array('Open','Bezig','Wacht op reactie','Afgerond'),
        ),
        'actions' => array(
            'default' => 'Open',
            'options' => array('Open','Bezig','Afgerond'),
        ),
    );
}

function bkp_status_options($context) {
    $definitions = bkp_status_definitions();
    return isset($definitions[$context]['options']) ? $definitions[$context]['options'] : array('Open','Bezig','Afgerond');
}

function bkp_status_default($context) {
    $definitions = bkp_status_definitions();
    return isset($definitions[$context]['default']) ? $definitions[$context]['default'] : 'Open';
}

function bkp_status_context_for_post($post_id, $posted_category='') {
    $post_type = get_post_type($post_id);
    if ($post_type === 'bkp_project') return 'projects';
    if ($post_type === 'bkp_inventory') return 'inventories';
    if ($post_type === 'bkp_action') return 'actions';
    if ($post_type === 'bkp_task') {
        $category = trim((string) $posted_category);
        if ($category === '') $category = trim((string) get_post_meta($post_id, '_bkp_category', true));
        return $category === 'To-do' ? 'todos' : 'project';
    }
    return '';
}

function bkp_sanitise_status($context, $value, $current='') {
    $value = sanitize_text_field((string) $value);
    $current = sanitize_text_field((string) $current);
    $options = bkp_status_options($context);
    if (in_array($value, $options, true)) return $value;
    // Behoud een bestaande afwijkende status wanneer die ongewijzigd wordt teruggestuurd.
    if ($current !== '' && $value === $current) return $current;
    return bkp_status_default($context);
}

function bkp_render_status_select($name, $context, $value='', $class='') {
    $value = trim((string) $value);
    $options = bkp_status_options($context);
    if ($value === '') $value = bkp_status_default($context);
    $legacy = $value !== '' && !in_array($value, $options, true);
    echo '<select name="'.esc_attr($name).'"'.($class !== '' ? ' class="'.esc_attr($class).'"' : '').'>';
    if ($legacy) echo '<option value="'.esc_attr($value).'" selected>Bestaand: '.esc_html($value).'</option>';
    foreach ($options as $status) {
        echo '<option value="'.esc_attr($status).'" '.selected($value, $status, false).'>'.esc_html($status).'</option>';
    }
    echo '</select>';
}

function bkp_meta_status_select($post, $key, $label, $context) {
    echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td>';
    bkp_render_status_select($key, $context, get_post_meta($post->ID, $key, true), 'regular-text');
    echo '</td></tr>';
}

function bkp_register_content_types() {
    $common = array(
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports' => array('title'),
        'map_meta_cap' => true,
    );
    register_post_type('bkp_project', array_merge($common, array('labels'=>array('name'=>'Projecten','singular_name'=>'Project','add_new_item'=>'Nieuw project','edit_item'=>'Project bewerken'))));
    register_post_type('bkp_event', array_merge($common, array('labels'=>array('name'=>'Activiteiten','singular_name'=>'Activiteit','add_new_item'=>'Nieuwe activiteit','edit_item'=>'Activiteit bewerken'))));
    register_post_type('bkp_task', array_merge($common, array('labels'=>array('name'=>'Planningstaken','singular_name'=>'Planningstaak','add_new_item'=>'Nieuwe planningstaak','edit_item'=>'Planningstaak bewerken'))));
    register_post_type('bkp_inventory', array_merge($common, array('labels'=>array('name'=>'Inventarisaties','singular_name'=>'Inventarisatie','add_new_item'=>'Nieuwe inventarisatie','edit_item'=>'Inventarisatie bewerken'))));
    register_post_type('bkp_action', array_merge($common, array('labels'=>array('name'=>'Acties en besluiten','singular_name'=>'Actie of besluit','add_new_item'=>'Nieuwe actie of besluit','edit_item'=>'Actie of besluit bewerken'))));
}
add_action('init', 'bkp_register_content_types');

/* Individuele bewerkschermen blijven als vangnet beschikbaar via de gridlinks. */
function bkp_add_meta_boxes() {
    add_meta_box('bkp_project_details','Projectgegevens','bkp_project_meta_box_html','bkp_project','normal','high');
    add_meta_box('bkp_event_details','Activiteitsgegevens','bkp_event_meta_box_html','bkp_event','normal','high');
    add_meta_box('bkp_task_details','Taakgegevens','bkp_task_meta_box_html','bkp_task','normal','high');
    add_meta_box('bkp_inventory_details','Inventarisatiegegevens','bkp_inventory_meta_box_html','bkp_inventory','normal','high');
    add_meta_box('bkp_action_details','Actie- of besluitgegevens','bkp_action_meta_box_html','bkp_action','normal','high');
}
add_action('add_meta_boxes', 'bkp_add_meta_boxes');

function bkp_meta_input($post, $key, $label, $type='text') {
    $value = get_post_meta($post->ID, $key, true);
    echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'"></td></tr>';
}
function bkp_meta_textarea($post, $key, $label) {
    echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><textarea class="large-text" rows="4" id="'.esc_attr($key).'" name="'.esc_attr($key).'">'.esc_textarea(get_post_meta($post->ID,$key,true)).'</textarea></td></tr>';
}

/**
 * WordPress-gebruikers die aan planningitems gekoppeld kunnen worden.
 * Alleen accounts met een geldig e-mailadres worden getoond, omdat de
 * koppeling primair wordt gebruikt voor automatische herinneringsmails.
 */
function bkp_assignable_users() {
    $users = get_users(array(
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID','display_name','user_email'),
    ));
    $out = array();
    foreach ((array) $users as $user) {
        $email = sanitize_email((string) $user->user_email);
        if (!$email || !is_email($email)) continue;
        $out[] = $user;
    }
    return $out;
}

function bkp_sanitise_linked_user_ids($value) {
    $ids = is_array($value) ? $value : preg_split('/[^0-9]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
    $out = array();
    foreach ((array) $ids as $id) {
        $id = absint($id);
        if (!$id || in_array($id, $out, true)) continue;
        $user = get_userdata($id);
        if (!$user || !is_email((string) $user->user_email)) continue;
        $out[] = $id;
    }
    return $out;
}

function bkp_get_linked_user_ids($post_id) {
    return bkp_sanitise_linked_user_ids(get_post_meta($post_id, '_bkp_assigned_user_ids', true));
}

function bkp_linked_user_names($post_id) {
    $names = array();
    foreach (bkp_get_linked_user_ids($post_id) as $user_id) {
        $user = get_userdata($user_id);
        if ($user) $names[] = $user->display_name;
    }
    return array_values(array_unique(array_filter($names)));
}

function bkp_user_picker_summary($selected_ids) {
    $names = array();
    foreach (bkp_sanitise_linked_user_ids($selected_ids) as $user_id) {
        $user = get_userdata($user_id);
        if ($user) $names[] = $user->display_name;
    }
    if (!$names) return 'Geen gebruiker gekoppeld';
    if (count($names) <= 2) return implode(', ', $names);
    return count($names) . ' gebruikers gekoppeld';
}

function bkp_render_user_picker($name, $selected_ids=array(), $class='') {
    $selected_ids = bkp_sanitise_linked_user_ids($selected_ids);
    $users = bkp_assignable_users();
    $classes = trim('bkp-user-picker ' . $class);
    echo '<details class="'.esc_attr($classes).'">';
    echo '<summary data-bkp-user-summary>'.esc_html(bkp_user_picker_summary($selected_ids)).'</summary>';
    echo '<div class="bkp-user-picker-list">';
    if (!$users) {
        echo '<p>Er zijn nog geen WordPress-gebruikers met een geldig e-mailadres.</p>';
    } else {
        foreach ($users as $user) {
            $checked = in_array((int) $user->ID, $selected_ids, true);
            echo '<label><input type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($user->ID).'" '.checked($checked,true,false).'> <span>'.esc_html($user->display_name).'</span><small>'.esc_html($user->user_email).'</small></label>';
        }
    }
    echo '</div></details>';
}

function bkp_meta_user_picker($post, $label='Gekoppelde gebruikers') {
    echo '<tr><th>'.esc_html($label).'</th><td>';
    bkp_render_user_picker('_bkp_assigned_user_ids', bkp_get_linked_user_ids($post->ID), 'bkp-user-picker--meta');
    echo '<p class="description">Deze accounts ontvangen herinneringsmails wanneer bij het item geen handmatige ontvanger is ingevuld.</p></td></tr>';
}
function bkp_meta_project_select($post, $label='Project') {
    echo '<tr><th>'.esc_html($label).'</th><td>';
    bkp_render_project_select('_bkp_project_id', bkp_get_project_id($post->ID), 'regular-text');
    echo '<p class="description">Koppel dit onderdeel aan de centrale projectpagina.</p></td></tr>';
}
function bkp_project_meta_box_html($post) {
    wp_nonce_field('bkp_save_meta','bkp_meta_nonce'); echo '<table class="form-table"><tbody>';
    bkp_meta_input($post,'_bkp_project_season','Seizoen');
    bkp_meta_status_select($post,'_bkp_project_status','Status','projects');
    bkp_meta_input($post,'_bkp_project_start','Startdatum','date');
    bkp_meta_input($post,'_bkp_project_end','Einddatum','date');
    bkp_meta_input($post,'_bkp_responsible','Projectleider / verantwoordelijke');
    bkp_meta_user_picker($post,'Gekoppelde projectleden');
    echo '<tr><th>Terugkerend project</th><td><label><input type="checkbox" name="_bkp_project_recurring" value="1" '.checked(get_post_meta($post->ID,'_bkp_project_recurring',true),'1',false).'> Ja, meenemen naar een nieuw seizoen</label></td></tr>';
    bkp_meta_textarea($post,'_bkp_notes','Omschrijving'); echo '</tbody></table>';
}
function bkp_event_meta_box_html($post) {
    wp_nonce_field('bkp_save_meta','bkp_meta_nonce'); echo '<table class="form-table"><tbody>';
    bkp_meta_project_select($post);
    bkp_meta_input($post,'_bkp_date','Datum','date'); bkp_meta_input($post,'_bkp_date_label','Periode bij ontbrekende datum');
    bkp_meta_input($post,'_bkp_time','Tijd'); bkp_meta_input($post,'_bkp_location','Locatie');
    bkp_meta_input($post,'_bkp_responsible','Wie'); bkp_meta_user_picker($post); bkp_meta_input($post,'_bkp_attire','Ornaat');
    echo '<tr><th>Vastgesteld</th><td><label><input type="checkbox" name="_bkp_confirmed" value="1" '.checked(get_post_meta($post->ID,'_bkp_confirmed',true),'1',false).'> Ja</label></td></tr>';
    echo '<tr><th>Openbare website</th><td><label><input type="checkbox" name="_bkp_public_web" value="1" '.checked(get_post_meta($post->ID,'_bkp_public_web',true),'1',false).'> Ja, deze activiteit tonen op www.cvdebillekletsers.nl</label><p class="description">Alleen de openbare velden worden via de koppeling gedeeld. Interne notities en verantwoordelijken worden nooit gepubliceerd.</p></td></tr>';
    bkp_meta_textarea($post,'_bkp_public_text','Tekst voor openbare website');
    bkp_meta_textarea($post,'_bkp_notes','Interne notities'); echo '</tbody></table>';
}
function bkp_task_meta_box_html($post) {
    wp_nonce_field('bkp_save_meta','bkp_meta_nonce'); echo '<table class="form-table"><tbody>';
    bkp_meta_project_select($post);
    if ((string) get_post_meta($post->ID,'_bkp_category',true) === 'To-do') bkp_meta_input($post,'_bkp_main_topic','Hoofdonderwerp');
    bkp_meta_input($post,'_bkp_responsible','Verantwoordelijke'); bkp_meta_user_picker($post); bkp_meta_input($post,'_bkp_period','Periode');
    $task_status_context = (string) get_post_meta($post->ID,'_bkp_category',true) === 'To-do' ? 'todos' : 'project';
    bkp_meta_status_select($post,'_bkp_task_status','Status',$task_status_context); bkp_meta_input($post,'_bkp_due_date','Deadline','date'); bkp_meta_input($post,'_bkp_category','Onderdeel');
    $selected = array_filter(explode(',', (string) get_post_meta($post->ID,'_bkp_months',true)));
    echo '<tr><th>Projectmaanden</th><td><fieldset>';
    foreach (bkp_month_definitions() as $key=>$label) echo '<label style="display:inline-block;min-width:150px;margin:0 12px 8px 0"><input type="checkbox" name="_bkp_months[]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'> '.esc_html($label).'</label>';
    echo '</fieldset></td></tr>'; bkp_meta_textarea($post,'_bkp_notes','Notities'); echo '</tbody></table>';
}
function bkp_inventory_meta_box_html($post) {
    wp_nonce_field('bkp_save_meta','bkp_meta_nonce'); echo '<table class="form-table"><tbody>';
    bkp_meta_project_select($post);
    bkp_meta_input($post,'_bkp_period','Periode');
    bkp_meta_input($post,'_bkp_due_date','Sluitdatum','date');
    echo '<tr><th>Open voor reacties</th><td><label><input type="checkbox" name="_bkp_inventory_open" value="1" '.checked(get_post_meta($post->ID,'_bkp_inventory_open',true),'1',false).'> Ja, leden mogen naam en aantal invullen</label></td></tr>';
    bkp_meta_status_select($post,'_bkp_task_status','Interne status','inventories');
    bkp_meta_input($post,'_bkp_responsible','Verantwoordelijke'); bkp_meta_user_picker($post);
    bkp_meta_textarea($post,'_bkp_notes','Toelichting');
    $stats=function_exists('bkp_inventory_response_stats')?bkp_inventory_response_stats($post->ID):array('count'=>0,'total'=>0);
    echo '<tr><th>Reacties huidig seizoen</th><td><strong>'.esc_html(number_format_i18n($stats['total'])).' totaal</strong> uit '.esc_html(number_format_i18n($stats['count'])).' naam/namen. <a href="'.esc_url(bkp_inventory_results_url($post->ID)).'">Resultaten bekijken</a></td></tr>';
    echo '</tbody></table>';
}
function bkp_action_meta_box_html($post) {
    wp_nonce_field('bkp_save_meta','bkp_meta_nonce'); echo '<table class="form-table"><tbody>';
    bkp_meta_project_select($post);
    bkp_meta_input($post,'_bkp_action_kind','A / B'); bkp_meta_input($post,'_bkp_date','Datum','date');
    bkp_meta_input($post,'_bkp_responsible','Wie'); bkp_meta_user_picker($post); bkp_meta_input($post,'_bkp_due_date','Einddatum','date'); bkp_meta_status_select($post,'_bkp_done','Status / afhandeling','actions');
    bkp_meta_textarea($post,'_bkp_notes','Notities'); echo '</tbody></table>';
}

function bkp_save_meta($post_id) {
    if (!isset($_POST['bkp_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkp_meta_nonce'])),'bkp_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post',$post_id)) return;
    $keys = array('_bkp_date','_bkp_date_label','_bkp_time','_bkp_location','_bkp_responsible','_bkp_attire','_bkp_period','_bkp_task_status','_bkp_category','_bkp_action_kind','_bkp_due_date','_bkp_done','_bkp_main_topic','_bkp_notes','_bkp_public_text','_bkp_spond_url','_bkp_result_summary','_bkp_project_season','_bkp_project_status','_bkp_project_start','_bkp_project_end');
    foreach ($keys as $key) {
        if (!isset($_POST[$key])) continue;
        $raw = wp_unslash($_POST[$key]);
        if ($key === '_bkp_spond_url') $value = esc_url_raw($raw, array('http','https'));
        elseif (in_array($key, array('_bkp_notes','_bkp_public_text','_bkp_result_summary'), true)) $value = sanitize_textarea_field($raw);
        elseif (in_array($key, array('_bkp_project_status','_bkp_task_status','_bkp_done'), true)) {
            $context = bkp_status_context_for_post($post_id, isset($_POST['_bkp_category']) ? sanitize_text_field(wp_unslash($_POST['_bkp_category'])) : '');
            $value = bkp_sanitise_status($context, $raw, get_post_meta($post_id, $key, true));
        } else $value = sanitize_text_field($raw);
        update_post_meta($post_id, $key, $value);
    }
    $linked_user_ids = bkp_sanitise_linked_user_ids(isset($_POST['_bkp_assigned_user_ids']) ? (array) wp_unslash($_POST['_bkp_assigned_user_ids']) : array());
    if ($linked_user_ids) update_post_meta($post_id, '_bkp_assigned_user_ids', $linked_user_ids);
    else delete_post_meta($post_id, '_bkp_assigned_user_ids');
    $project_id = isset($_POST['_bkp_project_id']) ? absint(wp_unslash($_POST['_bkp_project_id'])) : 0;
    if ($project_id && bkp_project_id_is_valid($project_id)) update_post_meta($post_id, '_bkp_project_id', $project_id);
    else delete_post_meta($post_id, '_bkp_project_id');
    if (get_post_type($post_id)==='bkp_project') {
        update_post_meta($post_id,'_bkp_project_recurring',isset($_POST['_bkp_project_recurring'])?'1':'0');
        if(trim((string)get_post_meta($post_id,'_bkp_project_season',true))==='') update_post_meta($post_id,'_bkp_project_season',sanitize_text_field((string)get_option('bkp_season','')));
        if(trim((string)get_post_meta($post_id,'_bkp_project_status',true))==='') update_post_meta($post_id,'_bkp_project_status','Voorbereiding');
    }
    if (get_post_type($post_id)==='bkp_inventory') update_post_meta($post_id,'_bkp_inventory_open',isset($_POST['_bkp_inventory_open'])?'1':'0');
    if (get_post_type($post_id)==='bkp_event') {
        update_post_meta($post_id,'_bkp_confirmed',isset($_POST['_bkp_confirmed'])?'1':'0');
        update_post_meta($post_id,'_bkp_public_web',isset($_POST['_bkp_public_web'])?'1':'0');
    }
    if (get_post_type($post_id)==='bkp_task') {
        $allowed = array_keys(bkp_month_definitions());
        $months = isset($_POST['_bkp_months']) ? (array) wp_unslash($_POST['_bkp_months']) : array();
        $months = array_values(array_intersect($allowed,array_map('sanitize_text_field',$months)));
        update_post_meta($post_id,'_bkp_months',implode(',',$months));
    }
}
add_action('save_post_bkp_project','bkp_save_meta');
add_action('save_post_bkp_event','bkp_save_meta');
add_action('save_post_bkp_task','bkp_save_meta');
add_action('save_post_bkp_inventory','bkp_save_meta');
add_action('save_post_bkp_action','bkp_save_meta');

function bkp_normalise_board($board) {
    $out = array();
    foreach ((array) $board as $row) {
        $role = sanitize_text_field((string)($row['role'] ?? ''));
        $name = sanitize_text_field((string)($row['name'] ?? ($row['people'] ?? '')));
        if ($role==='' && $name==='') continue;
        $out[] = array(
            'role'=>$role,
            'name'=>$name,
            'phone'=>sanitize_text_field((string)($row['phone'] ?? '')),
            'email'=>sanitize_email((string)($row['email'] ?? '')),
            'notes'=>sanitize_textarea_field((string)($row['notes'] ?? '')),
        );
    }
    return $out;
}

function bkp_normalise_committees($rows) {
    $out = array();
    foreach ((array) $rows as $row) {
        $committee = sanitize_text_field((string)($row['committee'] ?? ''));
        $name = sanitize_text_field((string)($row['name'] ?? ''));
        if ($committee==='' && $name==='') continue;
        $out[] = array(
            'committee'=>$committee,
            'name'=>$name,
            'role'=>sanitize_text_field((string)($row['role'] ?? '')),
            'phone'=>sanitize_text_field((string)($row['phone'] ?? '')),
            'email'=>sanitize_email((string)($row['email'] ?? '')),
            'notes'=>sanitize_textarea_field((string)($row['notes'] ?? '')),
            'visible'=>!empty($row['visible']) ? '1' : '0',
        );
    }
    return $out;
}

function bkp_committees() {
    return bkp_normalise_committees(get_option('bkp_committees',array()));
}

function bkp_committee_groups() {
    $groups = array();
    foreach (bkp_committees() as $row) {
        if ($row['visible']!=='1' || $row['committee']==='' || $row['name']==='') continue;
        if (!isset($groups[$row['committee']])) $groups[$row['committee']] = array();
        $groups[$row['committee']][] = $row;
    }
    return $groups;
}

function bkp_run_migrations() {
    $version = (string) get_option('bkp_schema_version','0');
    if (version_compare($version,BKP_CORE_SCHEMA_VERSION,'>=')) return;

    // De core-plugin neemt de bestaande opslagstructuur ongewijzigd over.
    // Er wordt bij installatie of update geen planningdata geïmporteerd,
    // doorgeschoven, verwijderd of anderszins aangepast.
    update_option('bkp_schema_version',BKP_CORE_SCHEMA_VERSION,false);
}

add_action('admin_init','bkp_run_migrations',1);

/* Afgeschermde voorkant. */
function bkp_cookie_token() { $hash=(string)get_option('bkp_access_hash'); return $hash?hash_hmac('sha256',$hash,wp_salt('auth')):''; }
function bkp_has_access() { if(is_user_logged_in()) return true; $token=bkp_cookie_token(); $cookie=isset($_COOKIE['bkp_access'])?sanitize_text_field(wp_unslash($_COOKIE['bkp_access'])):''; return $token&&$cookie&&hash_equals($token,$cookie); }
function bkp_set_access_cookie($value,$expires) { setcookie('bkp_access',$value,array('expires'=>$expires,'path'=>COOKIEPATH?:'/','domain'=>COOKIE_DOMAIN,'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax')); }
function bkp_client_key() { $ip=$_SERVER['REMOTE_ADDR']??'unknown'; return 'bkp_try_'.substr(hash('sha256',$ip),0,24); }
function bkp_lock_screen() {
    if(is_admin()||wp_doing_ajax()) return;
    if(isset($_GET['planning_logout'])) { bkp_set_access_cookie('',time()-3600); wp_safe_redirect(home_url('/')); exit; }
    if(bkp_has_access()) return;
    $error=''; $configured=(bool)get_option('bkp_access_hash');
    if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['bkp_password'])) {
        $key=bkp_client_key(); $tries=(int)get_transient($key);
        if($tries>=8) $error='Te veel pogingen. Probeer het over 15 minuten opnieuw.';
        elseif(!isset($_POST['bkp_lock_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkp_lock_nonce'])),'bkp_unlock')) $error='De sessie is verlopen. Probeer opnieuw.';
        elseif(wp_check_password((string)wp_unslash($_POST['bkp_password']),(string)get_option('bkp_access_hash'))) { delete_transient($key); bkp_set_access_cookie(bkp_cookie_token(),time()+DAY_IN_SECONDS*7); wp_safe_redirect(home_url('/')); exit; }
        else { set_transient($key,$tries+1,15*MINUTE_IN_SECONDS); $error='Het wachtwoord klopt niet.'; }
    }
    status_header(401); nocache_headers(); header('X-Robots-Tag: noindex, nofollow, noarchive',true);
    ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Jaarplanning openen</title><link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_uri()); ?>"></head><body><main class="bkp-lock"><section class="bkp-lock-card"><img src="<?php echo esc_url(BKP_CORE_URI.'/assets/images/logo-cropped.jpg'); ?>" alt="C.V. De Billekletsers"><h1>Jaarplanning openen</h1><p><strong>Werkende leden</strong> loggen in met hun persoonlijke account. Wie alleen wil meekijken, gebruikt het algemene wachtwoord.</p><?php if(!$configured): ?><div class="bkp-admin-note">Er is nog geen algemeen toegangswachtwoord ingesteld. Log als beheerder in en ga naar <strong>Jaarplanner → Instellingen</strong>.</div><?php else: ?><form class="bkp-lock-form" method="post"><?php wp_nonce_field('bkp_unlock','bkp_lock_nonce'); if($error): ?><div class="bkp-error"><?php echo esc_html($error); ?></div><?php endif; ?><label for="bkp_password">Algemeen wachtwoord</label><input class="bkp-field" type="password" id="bkp_password" name="bkp_password" required autocomplete="current-password"><button class="bkp-btn" type="submit">Bekijken met algemeen wachtwoord</button></form><?php endif; ?><a class="bkp-lock-login" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">Inloggen met persoonlijk account</a><?php if(function_exists('bkp_registration_is_open') && bkp_registration_is_open()): ?><a class="bkp-lock-login bkp-lock-register" href="<?php echo esc_url(bkp_registration_url()); ?>">Registreren als werkend lid</a><?php endif; ?><p class="bkp-lock-help">Na het inloggen kun je altijd alle gegevens bekijken. Bewerkknoppen verschijnen alleen wanneer daarvoor rechten zijn toegekend.</p></section></main></body></html><?php exit;
}
add_action('template_redirect','bkp_lock_screen',0);
add_filter('wp_robots',function($robots){$robots['noindex']=true;$robots['nofollow']=true;$robots['noarchive']=true;return $robots;});
add_action('send_headers',function(){header('X-Robots-Tag: noindex, nofollow, noarchive',true);});
add_filter('wp_sitemaps_enabled','__return_false');
add_filter('xmlrpc_enabled','__return_false');
add_filter('robots_txt',function(){return "User-agent: *\nDisallow: /\n";},99);
function bkp_is_public_rest_request() {
    $route = '';
    if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && !empty($GLOBALS['wp']->query_vars['rest_route'])) {
        $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
    }
    if ($route === '' && isset($_GET['rest_route'])) {
        $route = (string) wp_unslash($_GET['rest_route']);
    }
    if ($route === '' && !empty($_SERVER['REQUEST_URI'])) {
        $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
        $pos = strpos($path, $prefix);
        if ($pos !== false) {
            $route = substr($path, $pos + strlen($prefix));
        }
    }
    $route = '/' . ltrim($route, '/');
    return (bool) preg_match('#^/billekletsers/v1/(?:activiteiten|status)/?$#', $route);
}

add_filter('rest_authentication_errors', function($result) {
    if (!empty($result) || bkp_has_access() || bkp_is_public_rest_request()) return $result;
    return new WP_Error('bkp_private', 'Deze planning is afgeschermd.', array('status' => 401));
});

function bkp_get_posts_ordered($post_type,$category='') {
    $args=array('post_type'=>$post_type,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC');
    if($category) $args['meta_query']=array(array('key'=>'_bkp_category','value'=>$category));
    $posts=get_posts($args);
    usort($posts,function($a,$b) use ($category){
        if($category==='Projectplanning') {
            $ao=get_post_meta($a->ID,'_bkp_sort_order',true); $bo=get_post_meta($b->ID,'_bkp_sort_order',true);
            $ahas=$ao!==''; $bhas=$bo!=='';
            if($ahas&&$bhas&&(int)$ao!==(int)$bo) return (int)$ao<=>(int)$bo;
            if($ahas&&!$bhas) return -1; if(!$ahas&&$bhas) return 1;
        }
        $ar=(int)get_post_meta($a->ID,'_bkp_source_row',true); $br=(int)get_post_meta($b->ID,'_bkp_source_row',true);
        if($ar&&$br&&$ar!==$br) return $ar<=>$br;
        if($ar&&!$br) return -1; if(!$ar&&$br) return 1;
        return strcasecmp($a->post_title,$b->post_title);
    });
    return $posts;
}
function bkp_events() {
    $events=get_posts(array('post_type'=>'bkp_event','post_status'=>'publish','posts_per_page'=>-1));
    usort($events,function($a,$b){$ad=(string)get_post_meta($a->ID,'_bkp_date',true);$bd=(string)get_post_meta($b->ID,'_bkp_date',true);if($ad&&!$bd)return -1;if(!$ad&&$bd)return 1;if($ad!==$bd)return strcmp($ad,$bd);$ar=(int)get_post_meta($a->ID,'_bkp_source_row',true);$br=(int)get_post_meta($b->ID,'_bkp_source_row',true);return $ar<=>$br;}); return $events;
}
function bkp_tasks($category='') { return bkp_get_posts_ordered('bkp_task',$category); }

/** Bestaande hoofdonderwerpen die werkelijk aan een to-do zijn gekoppeld. */
function bkp_todo_main_topics() {
    $topics=array();
    foreach(bkp_tasks('To-do') as $task) {
        $topic=trim((string)get_post_meta($task->ID,'_bkp_main_topic',true));
        if($topic==='') continue;
        $key=function_exists('mb_strtolower')?mb_strtolower($topic,'UTF-8'):strtolower($topic);
        if(!isset($topics[$key])) $topics[$key]=$topic;
    }
    natcasesort($topics);
    return array_values($topics);
}

/** Keuzelijst voor snel muteren: bestaande onderwerpen, vaste voorbeelden en activiteitnamen. */
function bkp_todo_main_topic_suggestions() {
    $topics=array();
    $add=function($topic) use (&$topics) {
        $topic=trim((string)$topic);
        if($topic==='') return;
        $key=function_exists('mb_strtolower')?mb_strtolower($topic,'UTF-8'):strtolower($topic);
        if(!isset($topics[$key])) $topics[$key]=$topic;
    };
    foreach(array('Algemeen','Carnaval','Pronkzitting') as $topic) $add($topic);
    foreach(bkp_todo_main_topics() as $topic) $add($topic);
    foreach(bkp_events() as $event) $add($event->post_title);
    natcasesort($topics);
    return array_values($topics);
}
function bkp_inventories() { return bkp_get_posts_ordered('bkp_inventory'); }
function bkp_actions() { return bkp_get_posts_ordered('bkp_action'); }
function bkp_dutch_month($date) { if(!$date)return 'Zonder exacte datum';$m=array(1=>'januari',2=>'februari',3=>'maart',4=>'april',5=>'mei',6=>'juni',7=>'juli',8=>'augustus',9=>'september',10=>'oktober',11=>'november',12=>'december');$t=strtotime($date);return ($m[(int)date('n',$t)]??'').' '.date('Y',$t); }
function bkp_date_parts($date,$label='') { if(!$date)return array('—',$label?:'n.t.b.');$m=array(1=>'jan',2=>'feb',3=>'mrt',4=>'apr',5=>'mei',6=>'jun',7=>'jul',8=>'aug',9=>'sep',10=>'okt',11=>'nov',12=>'dec');$t=strtotime($date);return array(date('j',$t),$m[(int)date('n',$t)]??''); }
function bkp_format_date($date) { if(!$date)return 'Niet ingevuld';$m=array(1=>'januari',2=>'februari',3=>'maart',4=>'april',5=>'mei',6=>'juni',7=>'juli',8=>'augustus',9=>'september',10=>'oktober',11=>'november',12=>'december');$t=strtotime($date);return date('j',$t).' '.($m[(int)date('n',$t)]??'').' '.date('Y',$t); }
function bkp_is_done($value) { return in_array(strtolower(trim((string)$value)),array('ja','af','afgerond','gereed','klaar','done','voltooid'),true); }

function bkp_ics_download() {
    if(!isset($_GET['bkp_ics'])) return;
    if(!bkp_has_access()){status_header(403);exit('Geen toegang.');}
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="billekletsers-planning.ics"'); nocache_headers();
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//C.V. De Billekletsers//Planning//NL\r\nCALSCALE:GREGORIAN\r\n";
    $esc=function($s){return str_replace(array('\\',',',';',"\n"),array('\\\\','\\,','\\;','\\n'),(string)$s);};
    foreach(bkp_events() as $event){$date=get_post_meta($event->ID,'_bkp_date',true);if(!$date)continue;$desc='Tijd: '.get_post_meta($event->ID,'_bkp_time',true).' | Wie: '.get_post_meta($event->ID,'_bkp_responsible',true).' | Ornaat: '.get_post_meta($event->ID,'_bkp_attire',true);$notes=get_post_meta($event->ID,'_bkp_notes',true);if($notes)$desc.=' | Notities: '.$notes;echo "BEGIN:VEVENT\r\nUID:bkp-".$event->ID.'@'.wp_parse_url(home_url(),PHP_URL_HOST)."\r\nDTSTAMP:".gmdate('Ymd\\THis\\Z')."\r\nDTSTART;VALUE=DATE:".date('Ymd',strtotime($date))."\r\nSUMMARY:".$esc($event->post_title)."\r\nLOCATION:".$esc(get_post_meta($event->ID,'_bkp_location',true))."\r\nDESCRIPTION:".$esc($desc)."\r\nEND:VEVENT\r\n";}
    echo "END:VCALENDAR\r\n";exit;
}
add_action('template_redirect','bkp_ics_download',-1);


/* Beschermd documentenbeheer. Bestanden worden pas toegevoegd wanneer een beheerder ze zelf uploadt. */
function bkp_private_documents_storage() {
    $upload=wp_upload_dir();
    return array(
        'dir'=>trailingslashit($upload['basedir']).'bkp-private-documents',
        'url'=>trailingslashit($upload['baseurl']).'bkp-private-documents',
    );
}

function bkp_ensure_private_documents_dir() {
    $storage=bkp_private_documents_storage();
    if(!is_dir($storage['dir']) && !wp_mkdir_p($storage['dir'])) return new WP_Error('bkp_document_dir','De beveiligde documentenmap kon niet worden aangemaakt.');
    $htaccess=trailingslashit($storage['dir']).'.htaccess';
    if(!file_exists($htaccess)) @file_put_contents($htaccess,"Options -Indexes\nRequire all denied\nDeny from all\n");
    $index=trailingslashit($storage['dir']).'index.php';
    if(!file_exists($index)) @file_put_contents($index,"<?php\nhttp_response_code(403);\nexit;\n");
    return $storage;
}

function bkp_normalise_documents($rows) {
    $out=array();
    if(!is_array($rows)) return $out;
    foreach($rows as $row) {
        if(!is_array($row)) continue;
        $id=sanitize_key((string)($row['id']??''));
        $stored_name=sanitize_file_name((string)($row['stored_name']??''));
        if($id==='' || $stored_name==='') continue;
        $out[]=array(
            'id'=>$id,
            'title'=>sanitize_text_field((string)($row['title']??'')),
            'description'=>sanitize_textarea_field((string)($row['description']??'')),
            'stored_name'=>$stored_name,
            'original_name'=>sanitize_file_name((string)($row['original_name']??$stored_name)),
            'mime'=>sanitize_mime_type((string)($row['mime']??'application/octet-stream')),
            'size'=>absint($row['size']??0),
            'uploaded_at'=>sanitize_text_field((string)($row['uploaded_at']??'')),
            'visible'=>!empty($row['visible'])?'1':'0',
            'sort_order'=>intval($row['sort_order']??0),
            'project_id'=>bkp_project_id_is_valid(absint($row['project_id']??0)) ? absint($row['project_id']) : 0,
        );
    }
    usort($out,function($a,$b){
        if((int)$a['sort_order']!==(int)$b['sort_order']) return (int)$a['sort_order']<=>(int)$b['sort_order'];
        return strnatcasecmp($a['title'],$b['title']);
    });
    return $out;
}

function bkp_documents($visible_only=false) {
    $rows=bkp_normalise_documents(get_option('bkp_documents',array()));
    if(!$visible_only) return $rows;
    return array_values(array_filter($rows,function($row){return $row['visible']==='1';}));
}

function bkp_document_download_url($document) {
    return add_query_arg('bkp_document_download',sanitize_key((string)($document['id']??'')),home_url('/'));
}

function bkp_document_download() {
    if(empty($_GET['bkp_document_download'])) return;
    if(!bkp_has_access()){status_header(403);exit('Geen toegang.');}
    $id=sanitize_key(wp_unslash($_GET['bkp_document_download']));
    $found=null;
    foreach(bkp_documents(false) as $document) {
        if($document['id']===$id) {$found=$document;break;}
    }
    if(!$found || ($found['visible']!=='1' && !current_user_can('edit_posts') && !bkp_user_can_frontend_edit('documents'))) {status_header(404);exit('Document niet gevonden.');}
    $storage=bkp_private_documents_storage();
    $path=trailingslashit($storage['dir']).basename($found['stored_name']);
    if(!is_file($path) || !is_readable($path)) {status_header(404);exit('Documentbestand niet gevonden.');}
    $filename=$found['original_name']?:basename($path);
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: '.($found['mime']?:'application/octet-stream'));
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($filename)).'"; filename*=UTF-8\'\''.rawurlencode(basename($filename)));
    while(ob_get_level()) ob_end_clean();
    readfile($path);
    exit;
}
add_action('template_redirect','bkp_document_download',-3);

function bkp_csv_row($handle,$row=array()) {
    fputcsv($handle,array_map(function($value){return is_scalar($value)?(string)$value:'';},$row),';','"','\\');
}

function bkp_full_planning_download() {
    if(!isset($_GET['bkp_full_planning'])) return;
    if(!bkp_has_access()){status_header(403);exit('Geen toegang.');}
    $season=sanitize_file_name((string)get_option('bkp_season','jaarplanning'));
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="volledige-jaarplanning-'.$season.'.csv"');
    while(ob_get_level()) ob_end_clean();
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");

    bkp_csv_row($out,array('VOLLEDIGE JAARPLANNING C.V. DE BILLEKLETSERS'));
    bkp_csv_row($out,array('Seizoen',get_option('bkp_season','')));
    bkp_csv_row($out,array('Carnavalperiode',get_option('bkp_carnival_period','')));
    bkp_csv_row($out,array('Gedownload op',wp_date('d-m-Y H:i')));
    bkp_csv_row($out);

    bkp_csv_row($out,array('PROJECTEN'));
    bkp_csv_row($out,array('Project','Seizoen','Status','Startdatum','Einddatum','Projectleider','Terugkerend','Omschrijving'));
    foreach(bkp_projects(true) as $project) bkp_csv_row($out,array($project->post_title,get_post_meta($project->ID,'_bkp_project_season',true),bkp_project_status($project->ID),get_post_meta($project->ID,'_bkp_project_start',true),get_post_meta($project->ID,'_bkp_project_end',true),get_post_meta($project->ID,'_bkp_responsible',true),get_post_meta($project->ID,'_bkp_project_recurring',true)==='1'?'Ja':'Nee',get_post_meta($project->ID,'_bkp_notes',true)));
    bkp_csv_row($out);

    bkp_csv_row($out,array('ACTIVITEITEN'));
    bkp_csv_row($out,array('Project','Activiteit','Datum','Periode zonder datum','Tijd','Locatie','Wie','Ornaat','Status','Notities'));
    foreach(bkp_events() as $event) bkp_csv_row($out,array(
        bkp_project_name(bkp_get_project_id($event->ID),''),$event->post_title,get_post_meta($event->ID,'_bkp_date',true),get_post_meta($event->ID,'_bkp_date_label',true),
        get_post_meta($event->ID,'_bkp_time',true),get_post_meta($event->ID,'_bkp_location',true),get_post_meta($event->ID,'_bkp_responsible',true),
        get_post_meta($event->ID,'_bkp_attire',true),get_post_meta($event->ID,'_bkp_confirmed',true)==='1'?'Vastgesteld':'Concept',get_post_meta($event->ID,'_bkp_notes',true)
    ));
    bkp_csv_row($out);

    $months=bkp_month_definitions();
    bkp_csv_row($out,array('PROJECTPLANNING'));
    bkp_csv_row($out,array_merge(array('Project','Taak','Verantwoordelijke','Periode','Deadline','Status','Notities'),array_values($months)));
    foreach(bkp_tasks('Projectplanning') as $task) {
        $active=array_filter(explode(',',(string)get_post_meta($task->ID,'_bkp_months',true)));
        $row=array(bkp_project_name(bkp_get_project_id($task->ID),''),$task->post_title,get_post_meta($task->ID,'_bkp_responsible',true),get_post_meta($task->ID,'_bkp_period',true),get_post_meta($task->ID,'_bkp_due_date',true),get_post_meta($task->ID,'_bkp_task_status',true),get_post_meta($task->ID,'_bkp_notes',true));
        foreach(array_keys($months) as $key) $row[]=in_array($key,$active,true)?'X':'';
        bkp_csv_row($out,$row);
    }
    bkp_csv_row($out);

    bkp_csv_row($out,array('INVENTARISATIES'));
    bkp_csv_row($out,array('Project','Onderwerp','Periode','Sluitdatum','Open voor reacties','Verantwoordelijke','Interne status','Aantal namen','Totaal aantal','Toelichting'));
    foreach(bkp_inventories() as $item) {
        $stats=bkp_inventory_response_stats($item->ID);
        bkp_csv_row($out,array(
            bkp_project_name(bkp_get_project_id($item->ID),''),
            $item->post_title,
            get_post_meta($item->ID,'_bkp_period',true),
            get_post_meta($item->ID,'_bkp_due_date',true),
            bkp_inventory_is_open($item)?'Ja':'Nee',
            get_post_meta($item->ID,'_bkp_responsible',true),
            get_post_meta($item->ID,'_bkp_task_status',true),
            $stats['count'],
            $stats['total'],
            get_post_meta($item->ID,'_bkp_notes',true)
        ));
    }
    bkp_csv_row($out);

    bkp_csv_row($out,array('TO-DO'));
    bkp_csv_row($out,array('Project','Oud hoofdonderwerp','Onderwerp','Wie','Periode','Deadline','Status','Notities'));
    foreach(bkp_tasks('To-do') as $task) bkp_csv_row($out,array(bkp_project_name(bkp_get_project_id($task->ID),''),get_post_meta($task->ID,'_bkp_main_topic',true),$task->post_title,get_post_meta($task->ID,'_bkp_responsible',true),get_post_meta($task->ID,'_bkp_period',true),get_post_meta($task->ID,'_bkp_due_date',true),get_post_meta($task->ID,'_bkp_task_status',true),get_post_meta($task->ID,'_bkp_notes',true)));
    bkp_csv_row($out);

    bkp_csv_row($out,array('ACTIES EN BESLUITEN'));
    bkp_csv_row($out,array('Project','A/B','Datum','Actie of besluit','Wie','Einddatum','Af?','Notities'));
    foreach(bkp_actions() as $action) bkp_csv_row($out,array(bkp_project_name(bkp_get_project_id($action->ID),''),get_post_meta($action->ID,'_bkp_action_kind',true),get_post_meta($action->ID,'_bkp_date',true),$action->post_title,get_post_meta($action->ID,'_bkp_responsible',true),get_post_meta($action->ID,'_bkp_due_date',true),get_post_meta($action->ID,'_bkp_done',true),get_post_meta($action->ID,'_bkp_notes',true)));
    bkp_csv_row($out);

    bkp_csv_row($out,array('COMMISSIES'));
    bkp_csv_row($out,array('Commissie','Naam'));
    foreach(bkp_committee_groups() as $committee=>$members) foreach($members as $member) bkp_csv_row($out,array($committee,$member['name']));
    bkp_csv_row($out);

    bkp_csv_row($out,array('BESTUUR'));
    bkp_csv_row($out,array('Rol','Naam','Telefoon','E-mail','Notities'));
    foreach(bkp_normalise_board(get_option('bkp_board',array())) as $member) bkp_csv_row($out,array($member['role'],$member['name'],$member['phone'],$member['email'],$member['notes']));
    bkp_csv_row($out);

    $contact=(array)get_option('bkp_contact_details',array());
    bkp_csv_row($out,array('CONTACT'));
    bkp_csv_row($out,array('Contactpersoon',$contact['contact_name']??''));
    bkp_csv_row($out,array('Telefoon',$contact['phone']??''));
    bkp_csv_row($out,array('E-mail',$contact['email']??''));
    bkp_csv_row($out,array('Website',$contact['website']??''));
    bkp_csv_row($out,array('Adres',$contact['address']??''));
    bkp_csv_row($out,array('Toelichting',$contact['notes']??''));
    bkp_csv_row($out);

    bkp_csv_row($out,array('DOCUMENTEN'));
    bkp_csv_row($out,array('Project','Titel','Bestandsnaam','Beschrijving'));
    foreach(bkp_documents(true) as $document) bkp_csv_row($out,array(bkp_project_name(absint($document['project_id']??0),''),$document['title'],$document['original_name'],$document['description']));
    fclose($out);
    exit;
}
add_action('template_redirect','bkp_full_planning_download',-2);
