<?php
if (!defined('ABSPATH')) { exit; }

function bkp_admin_menu() {
    add_menu_page('Jaarplanner','Jaarplanner','edit_posts','bkp-planning','bkp_admin_dashboard_page','dashicons-calendar-alt',25);
    add_submenu_page('bkp-planning','Dashboard','Dashboard','edit_posts','bkp-planning','bkp_admin_dashboard_page');
    add_submenu_page('bkp-planning','Planning','Planning','edit_posts','bkp-planning-hub','bkp_admin_planning_hub_page');
    add_submenu_page('bkp-planning','Projecten','Projecten','edit_posts','bkp-projects',function(){ bkp_admin_grid_page('projects'); });
    add_submenu_page('bkp-planning','Taken','Taken','edit_posts','bkp-tasks-hub','bkp_admin_tasks_hub_page');
    add_submenu_page('bkp-planning','Organisatie','Organisatie','edit_posts','bkp-organisation-hub','bkp_admin_organisation_hub_page');
    add_submenu_page('bkp-planning','Documenten','Documenten','edit_posts','bkp-documents','bkp_admin_documents_page');
    add_submenu_page('bkp-planning','Gebruikers en rechten','Gebruikers & rechten','manage_options','bkp-editors','bkp_admin_editors_page');
    add_submenu_page('bkp-planning','Nieuw seizoen klaarzetten','Nieuw seizoen','manage_options','bkp-new-season','bkp_admin_new_season_page');
    add_submenu_page('bkp-planning','Instellingen','Instellingen','manage_options','bkp-settings','bkp_admin_settings_page');

    add_submenu_page(null,'Projectkoppelingen','Projectkoppelingen','manage_options','bkp-project-mapping','bkp_admin_project_mapping_page');

    // Detailpagina's blijven bereikbaar vanuit de overzichtspagina's, maar vullen het menu niet onnodig.
    add_submenu_page(null,'Jaarplanning','Jaarplanning','edit_posts','bkp-events',function(){ bkp_admin_grid_page('events'); });
    add_submenu_page(null,'Projectplanning','Projectplanning','edit_posts','bkp-project',function(){ bkp_admin_grid_page('project'); });
    add_submenu_page(null,'Inventarisaties','Inventarisaties','edit_posts','bkp-inventories',function(){ bkp_admin_grid_page('inventories'); });
    add_submenu_page(null,'To-do','To-do','edit_posts','bkp-todos',function(){ bkp_admin_grid_page('todos'); });
    add_submenu_page(null,'Acties en besluiten','Acties & besluiten','edit_posts','bkp-actions',function(){ bkp_admin_grid_page('actions'); });
    add_submenu_page(null,'Commissies','Commissies','edit_posts','bkp-committees','bkp_admin_committees_page');
    add_submenu_page(null,'Uitgangspunten','Uitgangspunten','edit_posts','bkp-principles','bkp_admin_principles_page');
    add_submenu_page(null,'Bestuur en contact','Bestuur & contact','manage_options','bkp-board','bkp_admin_board_page');
}
add_action('admin_menu','bkp_admin_menu');

function bkp_admin_assets($hook) {
    if (strpos((string)$hook, 'bkp-') === false && $hook !== 'toplevel_page_bkp-planning') return;
    wp_enqueue_style('bkp-admin',BKP_CORE_URI.'/assets/css/admin.css',array(),BKP_CORE_VERSION);
    wp_enqueue_script('bkp-admin-grid',BKP_CORE_URI.'/assets/js/admin-grid.js',array('jquery','jquery-ui-sortable'),BKP_CORE_VERSION,true);
}
add_action('admin_enqueue_scripts','bkp_admin_assets');

function bkp_admin_set_notice($type,$message) {
    set_transient('bkp_notice_'.get_current_user_id(),array('type'=>$type,'message'=>$message),60);
}
function bkp_admin_show_notice() {
    $key='bkp_notice_'.get_current_user_id(); $notice=get_transient($key); delete_transient($key);
    if(!$notice) return;
    $class=$notice['type']==='error'?'notice-error':'notice-success';
    echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html($notice['message']).'</p></div>';
}

function bkp_admin_entity_configs() {
    return array(
        'projects'=>array(
            'title'=>'Projecten','post_type'=>'bkp_project','category'=>'','page'=>'bkp-projects',
            'intro'=>'Projecten zijn de centrale kapstok voor activiteiten, planningstaken, to-do’s, inventarisaties, acties en documenten. Nieuwe projecten worden alleen opgeslagen wanneer je zelf op Grid opslaan klikt.',
            'fields'=>array(
                'title'=>array('label'=>'Project','type'=>'text','meta'=>'','wide'=>true),
                'season'=>array('label'=>'Seizoen','type'=>'text','meta'=>'_bkp_project_season'),
                'project_status'=>array('label'=>'Status','type'=>'status','status_context'=>'projects','meta'=>'_bkp_project_status'),
                'start'=>array('label'=>'Start','type'=>'date','meta'=>'_bkp_project_start'),
                'end'=>array('label'=>'Einde','type'=>'date','meta'=>'_bkp_project_end'),
                'responsible'=>array('label'=>'Projectleider','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Projectleden','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'recurring'=>array('label'=>'Terugkerend','type'=>'checkbox','meta'=>'_bkp_project_recurring'),
                'notes'=>array('label'=>'Omschrijving','type'=>'textarea','meta'=>'_bkp_notes','wide'=>true),
            ),
        ),
        'events'=>array(
            'title'=>'Jaarplanning','post_type'=>'bkp_event','category'=>'','page'=>'bkp-events',
            'intro'=>'Pas alle activiteiten in één overzicht aan. Horizontaal scrollen is mogelijk op kleinere schermen.',
            'fields'=>array(
                'project_id'=>array('label'=>'Project','type'=>'project','meta'=>'_bkp_project_id'),
                'title'=>array('label'=>'Activiteit','type'=>'text','meta'=>'','wide'=>true),
                'date'=>array('label'=>'Datum','type'=>'date','meta'=>'_bkp_date'),
                'date_label'=>array('label'=>'Periode zonder datum','type'=>'text','meta'=>'_bkp_date_label'),
                'confirmed'=>array('label'=>'Vastgesteld','type'=>'checkbox','meta'=>'_bkp_confirmed'),
                'public_web'=>array('label'=>'Website','type'=>'checkbox','meta'=>'_bkp_public_web'),
                'time'=>array('label'=>'Tijd','type'=>'text','meta'=>'_bkp_time'),
                'location'=>array('label'=>'Locatie','type'=>'text','meta'=>'_bkp_location'),
                'responsible'=>array('label'=>'Wie','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Gebruikers voor e-mail','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'attire'=>array('label'=>'Ornaat','type'=>'text','meta'=>'_bkp_attire'),
                'public_text'=>array('label'=>'Website tekst','type'=>'textarea','meta'=>'_bkp_public_text'),
                'notes'=>array('label'=>'Interne notities','type'=>'textarea','meta'=>'_bkp_notes'),
            ),
        ),
        'project'=>array(
            'title'=>'Projectplanning','post_type'=>'bkp_task','category'=>'Projectplanning','page'=>'bkp-project',
            'intro'=>'Vink per taak de actieve maanden aan. Sorteer taken via slepen, de pijlen of de snelle sorteerkeuze. De periode wordt bij opslaan automatisch berekend.',
            'fields'=>array(
                'project_id'=>array('label'=>'Project','type'=>'project','meta'=>'_bkp_project_id'),
                'title'=>array('label'=>'Taak','type'=>'text','meta'=>'','wide'=>true),
                'responsible'=>array('label'=>'Verantwoordelijke','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Gebruikers voor e-mail','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'months'=>array('label'=>'Maanden','type'=>'months','meta'=>'_bkp_months'),
                'due_date'=>array('label'=>'Deadline','type'=>'date','meta'=>'_bkp_due_date'),
                'status'=>array('label'=>'Status','type'=>'status','status_context'=>'project','meta'=>'_bkp_task_status'),
                'notes'=>array('label'=>'Notities','type'=>'textarea','meta'=>'_bkp_notes'),
            ),
        ),
        'inventories'=>array(
            'title'=>'Inventarisaties','post_type'=>'bkp_inventory','category'=>'','page'=>'bkp-inventories',
            'intro'=>'Maak een eenvoudige inventarisatie met alleen naam en aantal. Open of sluit reacties, bekijk het totaal en exporteer per inventarisatie naar Excel.',
            'fields'=>array(
                'project_id'=>array('label'=>'Project','type'=>'project','meta'=>'_bkp_project_id'),
                'title'=>array('label'=>'Onderwerp','type'=>'text','meta'=>'','wide'=>true),
                'period'=>array('label'=>'Periode','type'=>'text','meta'=>'_bkp_period'),
                'due_date'=>array('label'=>'Sluitdatum','type'=>'date','meta'=>'_bkp_due_date'),
                'open'=>array('label'=>'Open','type'=>'checkbox','meta'=>'_bkp_inventory_open'),
                'status'=>array('label'=>'Interne status','type'=>'status','status_context'=>'inventories','meta'=>'_bkp_task_status'),
                'responsible'=>array('label'=>'Verantwoordelijke','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Gekoppelde gebruikers','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'responses'=>array('label'=>'Resultaten','type'=>'inventory_responses','meta'=>''),
                'notes'=>array('label'=>'Toelichting','type'=>'textarea','meta'=>'_bkp_notes','wide'=>true),
            ),
        ),
        'todos'=>array(
            'title'=>'To-do','post_type'=>'bkp_task','category'=>'To-do','page'=>'bkp-todos',
            'intro'=>'Koppel ieder werkpunt aan een project, zoals Carnaval, Pronkzitting of Ludieke stunt. Het oude hoofdonderwerp blijft alleen als naslag zichtbaar.',
            'fields'=>array(
                'project_id'=>array('label'=>'Project','type'=>'project','meta'=>'_bkp_project_id'),
                'main_topic'=>array('label'=>'Oud hoofdonderwerp','type'=>'hidden_text','meta'=>'_bkp_main_topic'),
                'title'=>array('label'=>'Onderwerp','type'=>'text','meta'=>'','wide'=>true),
                'responsible'=>array('label'=>'Wie','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Gebruikers voor e-mail','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'period'=>array('label'=>'Periode','type'=>'text','meta'=>'_bkp_period'),
                'due_date'=>array('label'=>'Deadline','type'=>'date','meta'=>'_bkp_due_date'),
                'status'=>array('label'=>'Status','type'=>'status','status_context'=>'todos','meta'=>'_bkp_task_status'),
                'notes'=>array('label'=>'Notities','type'=>'textarea','meta'=>'_bkp_notes'),
            ),
        ),
        'actions'=>array(
            'title'=>'Acties en besluiten','post_type'=>'bkp_action','category'=>'','page'=>'bkp-actions',
            'intro'=>'Leg acties en besluiten vast met eigenaar, einddatum, afhandelstatus en notities.',
            'fields'=>array(
                'project_id'=>array('label'=>'Project','type'=>'project','meta'=>'_bkp_project_id'),
                'kind'=>array('label'=>'A / B','type'=>'text','meta'=>'_bkp_action_kind'),
                'date'=>array('label'=>'Datum','type'=>'date','meta'=>'_bkp_date'),
                'title'=>array('label'=>'Actie of besluit','type'=>'text','meta'=>'','wide'=>true),
                'responsible'=>array('label'=>'Wie','type'=>'text','meta'=>'_bkp_responsible'),
                'assigned_users'=>array('label'=>'Gebruikers voor e-mail','type'=>'users','meta'=>'_bkp_assigned_user_ids'),
                'due_date'=>array('label'=>'Einddatum','type'=>'date','meta'=>'_bkp_due_date'),
                'done'=>array('label'=>'Status / afhandeling','type'=>'status','status_context'=>'actions','meta'=>'_bkp_done'),
                'notes'=>array('label'=>'Notities','type'=>'textarea','meta'=>'_bkp_notes'),
            ),
        ),
    );
}

function bkp_admin_handle_actions() {
    if (!is_admin() || empty($_POST['bkp_admin_action'])) return;
    $action=sanitize_key(wp_unslash($_POST['bkp_admin_action']));

    if ($action==='import_excel') {
        if(!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkp_import_excel');
        bkp_admin_set_notice('error','Excelimport is uitgeschakeld om bestaande handmatige wijzigingen te beschermen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-planning')); exit;
    }


    if ($action==='save_settings') {
        if(!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_settings');
        $password=(string)wp_unslash($_POST['access_password']??'');
        if($password!=='') update_option('bkp_access_hash',wp_hash_password($password),false);
        update_option('bkp_intro_text',sanitize_textarea_field(wp_unslash($_POST['intro_text']??'')),false);
        update_option('bkp_season',sanitize_text_field(wp_unslash($_POST['season']??'')),false);
        update_option('bkp_carnival_period',sanitize_text_field(wp_unslash($_POST['carnival_period']??'')),false);
        bkp_admin_set_notice('success','De algemene instellingen zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-settings')); exit;
    }

    if ($action==='save_frontend_permissions') {
        if(!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_frontend_permissions');
        $allowed=array_keys(bkp_frontend_sections());
        $posted=isset($_POST['permissions'])?(array)wp_unslash($_POST['permissions']):array();
        $out=array();
        foreach($posted as $user_id=>$sections) {
            $user_id=absint($user_id);
            if(!$user_id || !get_userdata($user_id)) continue;
            $clean=array_values(array_intersect($allowed,array_map('sanitize_key',(array)$sections)));
            if($clean) $out[$user_id]=$clean;
        }
        update_option('bkp_frontend_permissions',$out,false);
        bkp_admin_set_notice('success','De frontend bewerkrechten zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-editors')); exit;
    }

    if ($action==='save_board') {
        if(!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_board');
        $rows=isset($_POST['board'])?(array)wp_unslash($_POST['board']):array();
        $kept=array(); foreach($rows as $row) if(empty($row['delete'])) $kept[]=$row;
        update_option('bkp_board',bkp_normalise_board($kept),false);
        $contact=array(
            'contact_name'=>sanitize_text_field(wp_unslash($_POST['contact_name']??'')),
            'phone'=>sanitize_text_field(wp_unslash($_POST['contact_phone']??'')),
            'email'=>sanitize_email(wp_unslash($_POST['contact_email']??'')),
            'website'=>esc_url_raw(wp_unslash($_POST['contact_website']??'')),
            'address'=>sanitize_textarea_field(wp_unslash($_POST['contact_address']??'')),
            'notes'=>sanitize_textarea_field(wp_unslash($_POST['contact_notes']??'')),
        );
        update_option('bkp_contact_details',$contact,false);
        $summary=trim($contact['notes']);
        if(!$summary) {
            $parts=array_filter(array($contact['email'],$contact['phone'],$contact['address']));
            $summary=implode("\n",$parts);
        }
        update_option('bkp_contact',$summary,false);
        bkp_admin_set_notice('success','Bestuur en contactgegevens zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-board')); exit;
    }

    if ($action==='add_committee_member') {
        if(!current_user_can('edit_posts')) wp_die('Geen toegang.');
        check_admin_referer('bkp_add_committee_member');
        $name=sanitize_text_field(wp_unslash($_POST['member_name']??''));
        $choice=sanitize_text_field(wp_unslash($_POST['committee_choice']??''));
        $new_committee=sanitize_text_field(wp_unslash($_POST['new_committee']??''));
        $committee=$choice==='__new__'?$new_committee:$choice;
        if($name==='' || $committee==='') {
            bkp_admin_set_notice('error','Vul een naam in en kies een bestaande of nieuwe commissie.');
            wp_safe_redirect(admin_url('admin.php?page=bkp-committees')); exit;
        }
        $rows=bkp_committees(); $duplicate=false;
        foreach($rows as $row) {
            $row_name=function_exists('mb_strtolower')?mb_strtolower((string)$row['name'],'UTF-8'):strtolower((string)$row['name']);
            $row_committee=function_exists('mb_strtolower')?mb_strtolower((string)$row['committee'],'UTF-8'):strtolower((string)$row['committee']);
            $test_name=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
            $test_committee=function_exists('mb_strtolower')?mb_strtolower($committee,'UTF-8'):strtolower($committee);
            if($row_name===$test_name && $row_committee===$test_committee) { $duplicate=true; break; }
        }
        if($duplicate) {
            bkp_admin_set_notice('error',$name.' staat al bij '.$committee.'.');
        } else {
            $rows[]=array('committee'=>$committee,'name'=>$name,'role'=>'','phone'=>'','email'=>'','notes'=>'','visible'=>'1');
            update_option('bkp_committees',bkp_normalise_committees($rows),false);
            bkp_admin_set_notice('success',$name.' is toegevoegd aan '.$committee.'.');
        }
        wp_safe_redirect(admin_url('admin.php?page=bkp-committees')); exit;
    }

    if ($action==='save_committee_members') {
        if(!current_user_can('edit_posts')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_committee_members');
        $current=bkp_committees();
        $delete_committee=sanitize_text_field(wp_unslash($_POST['delete_committee']??''));
        if($delete_committee!=='') {
            $kept=array();
            foreach($current as $row) if((string)$row['committee']!==$delete_committee) $kept[]=$row;
            update_option('bkp_committees',bkp_normalise_committees($kept),false);
            bkp_admin_set_notice('success','De commissie '.$delete_committee.' is verwijderd.');
            wp_safe_redirect(admin_url('admin.php?page=bkp-committees')); exit;
        }
        $posted=isset($_POST['members'])?(array)wp_unslash($_POST['members']):array();
        $out=array(); $seen=array();
        foreach($posted as $index=>$entry) {
            $index=absint($index);
            if(!isset($current[$index])) continue;
            $seen[$index]=true;
            if(!empty($entry['delete'])) continue;
            $row=$current[$index];
            $name=sanitize_text_field($entry['name']??'');
            $committee=sanitize_text_field($entry['committee']??($row['committee']??''));
            if($name==='') continue;
            $row['name']=$name;
            $row['committee']=$committee;
            $out[]=$row;
        }
        foreach($current as $index=>$row) if(empty($seen[$index])) $out[]=$row;
        update_option('bkp_committees',bkp_normalise_committees($out),false);
        bkp_admin_set_notice('success','De namen bij de commissies zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-committees')); exit;
    }

    if ($action==='add_document') {
        if(!current_user_can('upload_files')) wp_die('Geen toegang.');
        check_admin_referer('bkp_add_document');
        if(empty($_FILES['document_file']) || !isset($_FILES['document_file']['error']) || (int)$_FILES['document_file']['error']!==UPLOAD_ERR_OK) {
            bkp_admin_set_notice('error','Selecteer een geldig document om te uploaden.');
            wp_safe_redirect(admin_url('admin.php?page=bkp-documents')); exit;
        }
        $file=$_FILES['document_file'];
        $original=sanitize_file_name((string)$file['name']);
        $max=min((int)wp_max_upload_size(),50*1024*1024);
        $type=wp_check_filetype($original,get_allowed_mime_types());
        if(empty($type['ext']) || empty($type['type'])) {
            bkp_admin_set_notice('error','Dit bestandstype is niet toegestaan door WordPress.');
        } elseif((int)$file['size']>$max) {
            bkp_admin_set_notice('error','Het document is groter dan de toegestane uploadlimiet.');
        } elseif(!is_uploaded_file((string)$file['tmp_name'])) {
            bkp_admin_set_notice('error','De upload kon niet veilig worden verwerkt.');
        } else {
            $storage=bkp_ensure_private_documents_dir();
            if(is_wp_error($storage)) {
                bkp_admin_set_notice('error',$storage->get_error_message());
            } else {
                $stored=wp_unique_filename($storage['dir'],wp_generate_password(12,false,false).'-'.$original);
                $target=trailingslashit($storage['dir']).$stored;
                if(!@move_uploaded_file((string)$file['tmp_name'],$target)) {
                    bkp_admin_set_notice('error','Het document kon niet in de beveiligde map worden opgeslagen.');
                } else {
                    @chmod($target,0640);
                    $rows=bkp_documents(false);
                    $title=sanitize_text_field(wp_unslash($_POST['document_title']??''));
                    if($title==='') $title=pathinfo($original,PATHINFO_FILENAME);
                    $rows[]=array(
                        'id'=>str_replace('-','',wp_generate_uuid4()),
                        'title'=>$title,
                        'description'=>sanitize_textarea_field(wp_unslash($_POST['document_description']??'')),
                        'stored_name'=>$stored,
                        'original_name'=>$original,
                        'mime'=>$type['type'],
                        'size'=>(int)filesize($target),
                        'uploaded_at'=>current_time('mysql'),
                        'visible'=>!empty($_POST['document_visible'])?'1':'0',
                        'sort_order'=>count($rows)+1,
                        'project_id'=>bkp_project_id_is_valid(absint($_POST['document_project_id']??0))?absint($_POST['document_project_id']):0,
                    );
                    update_option('bkp_documents',bkp_normalise_documents($rows),false);
                    bkp_admin_set_notice('success','Het document is toegevoegd.');
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=bkp-documents')); exit;
    }

    if ($action==='save_documents') {
        if(!current_user_can('edit_posts')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_documents');
        $current=bkp_documents(false); $map=array();
        foreach($current as $document) $map[$document['id']]=$document;
        $posted=isset($_POST['documents'])?(array)wp_unslash($_POST['documents']):array();
        $out=array(); $storage=bkp_private_documents_storage();
        foreach($current as $document) {
            $id=$document['id'];
            if(!isset($posted[$id]) || !is_array($posted[$id])) { $out[]=$document; continue; }
            $row=$posted[$id];
            if(!empty($row['delete'])) {
                $path=trailingslashit($storage['dir']).basename($document['stored_name']);
                if(is_file($path)) @unlink($path);
                continue;
            }
            $document['title']=sanitize_text_field($row['title']??'')?:$document['original_name'];
            $document['description']=sanitize_textarea_field($row['description']??'');
            $document['visible']=!empty($row['visible'])?'1':'0';
            $document['sort_order']=intval($row['sort_order']??0);
            $document['project_id']=bkp_project_id_is_valid(absint($row['project_id']??0))?absint($row['project_id']):0;
            $out[]=$document;
        }
        update_option('bkp_documents',bkp_normalise_documents($out),false);
        bkp_admin_set_notice('success','De documenten zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-documents')); exit;
    }

    if ($action==='save_principles') {
        if(!current_user_can('edit_posts')) wp_die('Geen toegang.');
        check_admin_referer('bkp_save_principles');
        $rows=isset($_POST['principles'])?(array)wp_unslash($_POST['principles']):array(); $out=array();
        foreach($rows as $row) {
            if(!empty($row['delete'])) continue;
            $title=sanitize_text_field($row['title']??''); if($title==='') continue;
            $out[]=array('section'=>in_array(($row['section']??''),array('Uitgangspunten','Te controleren'),true)?$row['section']:'Uitgangspunten','title'=>$title,'value'=>sanitize_text_field($row['value']??''),'note'=>sanitize_textarea_field($row['note']??''),'source_key'=>sanitize_key($row['source_key']??''),'source_row'=>absint($row['source_row']??0));
        }
        update_option('bkp_principles',$out,false);
        bkp_admin_set_notice('success','De uitgangspunten zijn opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-principles')); exit;
    }


    if ($action==='apply_project_mapping') {
        if(!current_user_can('manage_options')) wp_die('Geen toegang.');
        check_admin_referer('bkp_apply_project_mapping');
        $selected=isset($_POST['topics'])?(array)wp_unslash($_POST['topics']):array();
        $created=0; $linked=0;
        $candidates=bkp_project_migration_candidates();
        foreach($selected as $key=>$entry) {
            $key=sanitize_key($key);
            if(empty($entry['include']) || empty($candidates[$key])) continue;
            $candidate=$candidates[$key];
            $project_id=absint($entry['project_id']??0);
            if(!$project_id || !bkp_project_id_is_valid($project_id)) $project_id=bkp_find_project_by_title($candidate['name']);
            if(!$project_id) {
                $project_id=wp_insert_post(array('post_type'=>'bkp_project','post_status'=>'publish','post_title'=>$candidate['name']),true);
                if(is_wp_error($project_id) || !$project_id) continue;
                update_post_meta($project_id,'_bkp_project_season',sanitize_text_field((string)get_option('bkp_season','')));
                update_post_meta($project_id,'_bkp_project_status','Voorbereiding');
                update_post_meta($project_id,'_bkp_project_recurring','1');
                $created++;
            }
            foreach($candidate['items'] as $todo) {
                if(bkp_get_project_id($todo->ID)) continue;
                update_post_meta($todo->ID,'_bkp_project_id',absint($project_id));
                $linked++;
            }
        }
        bkp_admin_set_notice('success',$created.' project(en) aangemaakt en '.$linked.' bestaande to-do(s) gekoppeld. De oude hoofdonderwerpen zijn als naslag behouden.');
        wp_safe_redirect(admin_url('admin.php?page=bkp-project-mapping')); exit;
    }

    if (strpos($action,'save_grid_')===0) {
        if(!current_user_can('edit_posts')) wp_die('Geen toegang.');
        $entity=substr($action,10); $configs=bkp_admin_entity_configs();
        if(empty($configs[$entity])) wp_die('Onbekend planningonderdeel.');
        check_admin_referer('bkp_save_'.$entity);
        $saved=bkp_admin_save_grid($entity,$configs[$entity]);
        bkp_admin_set_notice('success',$saved.' regel(s) opgeslagen.');
        wp_safe_redirect(admin_url('admin.php?page='.$configs[$entity]['page'])); exit;
    }
}
add_action('admin_init','bkp_admin_handle_actions',20);

function bkp_admin_save_grid($entity,$config) {
    $rows=isset($_POST['rows'])?(array)wp_unslash($_POST['rows']):array(); $saved=0; $position=0;
    foreach($rows as $row) {
        $position++; $id=absint($row['id']??0);
        if(!empty($row['delete'])) { if($id && current_user_can('delete_post',$id)) { if($entity==='projects' && bkp_project_link_count($id)>0) { continue; } wp_delete_post($id,true); } continue; }
        $title=sanitize_text_field($row['title']??''); if($title==='') continue;
        $postarr=array('post_type'=>$config['post_type'],'post_status'=>'publish','post_title'=>$title);
        if($id && get_post_type($id)===$config['post_type']) {
            if(!current_user_can('edit_post',$id)) continue;
            $postarr['ID']=$id;
        }
        $post_id=isset($postarr['ID'])?wp_update_post($postarr,true):wp_insert_post($postarr,true);
        if(is_wp_error($post_id)||!$post_id) continue;
        if(!$id) { delete_post_meta($post_id,'_bkp_imported'); delete_post_meta($post_id,'_bkp_source_key'); update_post_meta($post_id,'_bkp_source_row',100000+$post_id); }
        if($config['category']) update_post_meta($post_id,'_bkp_category',$config['category']);
        if($entity==='projects' && trim((string)get_post_meta($post_id,'_bkp_project_season',true))==='') update_post_meta($post_id,'_bkp_project_season',sanitize_text_field((string)get_option('bkp_season','')));
        foreach($config['fields'] as $key=>$field) {
            if($key==='title'||in_array($field['type'],array('months','inventory_responses'),true)) continue;
            $meta=$field['meta'];
            if($field['type']==='users') {
                $value=bkp_sanitise_linked_user_ids($row[$key]??array());
                if($value) update_post_meta($post_id,$meta,$value);
                else delete_post_meta($post_id,$meta);
                continue;
            }
            if($field['type']==='project') {
                $value=absint($row[$key]??0);
                if($value && bkp_project_id_is_valid($value)) update_post_meta($post_id,$meta,$value);
                else delete_post_meta($post_id,$meta);
                continue;
            }
            if($field['type']==='status') $value=bkp_sanitise_status($field['status_context']??'', $row[$key]??'', $id?get_post_meta($post_id,$meta,true):'');
            elseif($field['type']==='checkbox') $value=!empty($row[$key])?'1':'0';
            elseif($field['type']==='textarea') $value=sanitize_textarea_field($row[$key]??'');
            elseif($field['type']==='url') $value=esc_url_raw($row[$key]??'',array('http','https'));
            elseif($field['type']==='email') $value=sanitize_email($row[$key]??'');
            else $value=sanitize_text_field($row[$key]??'');
            update_post_meta($post_id,$meta,$value);
        }
        if(isset($config['fields']['months'])) {
            $allowed=array_keys(bkp_month_definitions()); $selected=array();
            foreach((array)($row['months']??array()) as $month=>$checked) if($checked && in_array($month,$allowed,true)) $selected[]=$month;
            update_post_meta($post_id,'_bkp_months',implode(',',$selected));
            update_post_meta($post_id,'_bkp_period',bkp_admin_month_period($selected));
            if(trim((string)get_post_meta($post_id,'_bkp_task_status',true))==='') update_post_meta($post_id,'_bkp_task_status',$selected?'Gepland':'Nog in te plannen');
        }
        if($entity==='project') update_post_meta($post_id,'_bkp_sort_order',max(0,absint($row['sort_order']??($position-1))));
        $saved++;
    }
    return $saved;
}

function bkp_admin_month_period($selected) {
    if(!$selected) return '';
    $defs=bkp_month_definitions(); $first=$defs[$selected[0]]??$selected[0]; $last=$defs[$selected[count($selected)-1]]??end($selected);
    return $first===$last?$first:$first.' – '.$last;
}

function bkp_admin_dashboard_page() {
    if(!current_user_can('edit_posts')) return;
    $counts=array('Projecten'=>count(bkp_projects(true)),'Jaarplanning'=>count(bkp_events()),'Projecttaken'=>count(bkp_tasks('Projectplanning')),'Inventarisaties'=>count(bkp_inventories()),'To-do’s'=>count(bkp_tasks('To-do')),'Acties/besluiten'=>count(bkp_actions()),'Commissieleden'=>count(bkp_committees()),'Documenten'=>count(bkp_documents(false)),'Frontend bewerkers'=>count(bkp_frontend_permissions()));
    if(current_user_can('manage_options') && function_exists('bkp_registration_pending_count')) $counts['Registratieaanvragen']=bkp_registration_pending_count();
    echo '<div class="wrap bkp-admin-wrap"><h1>Jaarplanning</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Beheer de volledige planning via de onderdelen in het menu. De centrale Jaarplanner-plugin importeert, synchroniseert of overschrijft geen bestaande planningdata.</p><div class="bkp-admin-cards">';
    $links=array('Projecten'=>'bkp-projects','Jaarplanning'=>'bkp-events','Projecttaken'=>'bkp-project','Inventarisaties'=>'bkp-inventories','To-do’s'=>'bkp-todos','Acties/besluiten'=>'bkp-actions','Commissieleden'=>'bkp-committees','Documenten'=>'bkp-documents','Frontend bewerkers'=>'bkp-editors','Registratieaanvragen'=>'bkp-registrations');
    foreach($counts as $label=>$count) echo '<a class="bkp-admin-card" href="'.esc_url(admin_url('admin.php?page='.$links[$label])).'"><strong>'.esc_html($count).'</strong><span>'.esc_html($label).'</span></a>';
    echo '</div><div class="bkp-admin-columns"><section class="bkp-admin-panel"><h2>Databehoud</h2><div class="notice notice-info inline"><p><strong>Excelimport is uitgeschakeld.</strong> Bestaande activiteiten, taken, commissies, bestuursgegevens en handmatige wijzigingen worden door deze update niet aangepast.</p></div><p>Nieuwe wijzigingen worden uitsluitend opgeslagen wanneer je ze zelf in een onderdeel wijzigt en op opslaan klikt.</p><p><a class="button" href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">Planning bekijken</a> <a class="button" href="'.esc_url(add_query_arg('bkp_full_planning','1',home_url('/'))).'">Volledige jaarplanning downloaden</a> '.(current_user_can('manage_options')?'<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bkp-new-season')).'">Nieuw seizoen klaarzetten</a>':'').'</p></section><section class="bkp-admin-panel"><h2>Algemene instellingen</h2>';
    if(current_user_can('manage_options')) {
        $configured=(bool)get_option('bkp_access_hash');
        echo '<form method="post">'; wp_nonce_field('bkp_save_settings'); echo '<input type="hidden" name="bkp_admin_action" value="save_settings"><table class="form-table"><tr><th>Beveiliging</th><td><strong>'.($configured?'Algemeen kijkwachtwoord ingesteld':'Nog geen algemeen kijkwachtwoord ingesteld').'</strong></td></tr><tr><th><label for="access_password">Nieuw algemeen kijkwachtwoord</label></th><td><input class="regular-text" type="password" id="access_password" name="access_password" autocomplete="new-password"><p class="description">Leeg laten om het huidige algemene kijkwachtwoord te behouden.</p></td></tr><tr><th><label for="season">Seizoen</label></th><td><input class="regular-text" id="season" name="season" value="'.esc_attr(get_option('bkp_season','2026-2027')).'"></td></tr><tr><th><label for="carnival_period">Carnavalperiode</label></th><td><input class="regular-text" id="carnival_period" name="carnival_period" value="'.esc_attr(get_option('bkp_carnival_period','')).'"></td></tr><tr><th><label for="intro_text">Introtekst</label></th><td><textarea class="large-text" rows="4" id="intro_text" name="intro_text">'.esc_textarea(get_option('bkp_intro_text','Alle activiteiten, taken, inventarisaties en aandachtspunten voor het carnavalsseizoen op één plek.')).'</textarea></td></tr></table>'; submit_button('Instellingen opslaan'); echo '</form>';
    }
    echo '</section></div></div>';
}

function bkp_admin_get_grid_posts($config) {
    $args=array('post_type'=>$config['post_type'],'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC');
    if($config['category']) $args['meta_query']=array(array('key'=>'_bkp_category','value'=>$config['category']));
    $posts=get_posts($args);
    usort($posts,function($a,$b) use ($config){
        if(($config['category']??'')==='Projectplanning') {
            $ao=get_post_meta($a->ID,'_bkp_sort_order',true); $bo=get_post_meta($b->ID,'_bkp_sort_order',true);
            $ahas=$ao!==''; $bhas=$bo!=='';
            if($ahas&&$bhas&&(int)$ao!==(int)$bo) return (int)$ao<=>(int)$bo;
            if($ahas&&!$bhas) return -1; if(!$ahas&&$bhas) return 1;
        }
        $ar=(int)get_post_meta($a->ID,'_bkp_source_row',true); $br=(int)get_post_meta($b->ID,'_bkp_source_row',true);
        if(($ar?:999999)!==($br?:999999)) return ($ar?:999999)<=>($br?:999999);
        return strcasecmp($a->post_title,$b->post_title);
    });
    return $posts;
}

function bkp_admin_grid_page($entity) {
    $configs=bkp_admin_entity_configs(); if(empty($configs[$entity])||!current_user_can('edit_posts')) return; $config=$configs[$entity]; $posts=bkp_admin_get_grid_posts($config);
    echo '<div class="wrap bkp-admin-wrap"><h1>'.esc_html($config['title']).'</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">'.esc_html($config['intro']).'</p><div class="bkp-grid-toolbar"><input type="search" class="bkp-grid-search" placeholder="Filter regels…">';
    if($entity==='project') echo '<label class="bkp-sort-control"><span class="screen-reader-text">Sorteren op</span><select class="bkp-project-sort"><option value="custom">Eigen volgorde</option><option value="title">Taak A–Z</option><option value="responsible">Verantwoordelijke A–Z</option><option value="month">Eerste maand</option><option value="status">Status A–Z</option></select><button type="button" class="button bkp-apply-project-sort">Sorteren</button></label>';
    echo '<button type="button" class="button bkp-add-row">Nieuwe regel</button><span class="bkp-grid-count">'.count($posts).' regels</span></div>';
    echo '<form method="post" class="bkp-grid-form'.($entity==='project'?' bkp-project-grid-form':'').'">'; wp_nonce_field('bkp_save_'.$entity); echo '<input type="hidden" name="bkp_admin_action" value="save_grid_'.esc_attr($entity).'"><div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid"><thead><tr>';
    if($entity==='project') echo '<th class="bkp-col-order">Volgorde</th>';
    echo '<th class="bkp-col-number">#</th>';
    foreach($config['fields'] as $field) {
        if($field['type']==='months') foreach(bkp_month_definitions() as $key=>$label) echo '<th class="bkp-month-column" title="'.esc_attr($label).'">'.esc_html(ucfirst(substr($label,0,3))).'</th>';
        else echo '<th class="'.(!empty($field['wide'])?'bkp-col-wide':'').'">'.esc_html($field['label']).'</th>';
    }
    echo '<th>Bron</th><th>Verwijderen</th></tr></thead><tbody class="bkp-grid-body">';
    $index=0; foreach($posts as $post) { bkp_admin_render_grid_row($entity,$config,$post,$index++); }
    echo '</tbody></table></div><template class="bkp-row-template">'; bkp_admin_render_grid_row($entity,$config,null,'__INDEX__'); echo '</template><div class="bkp-save-bar"><span>Wijzigingen worden pas toegepast na opslaan.</span>'; submit_button('Grid opslaan','primary','submit',false); echo '</div></form>';
    if($entity==='todos') {
        echo '<datalist id="bkp-todo-main-topics">';
        foreach(bkp_todo_main_topic_suggestions() as $topic) echo '<option value="'.esc_attr($topic).'"></option>';
        echo '</datalist>';
    }
    if($entity==='projects') echo '<section class="bkp-admin-panel bkp-project-mapping-callout"><h2>Bestaande hoofdonderwerpen koppelen</h2><p>To-do’s met een oud hoofdonderwerp worden niet automatisch gewijzigd. Open de koppelassistent om eerst te controleren welke projecten kunnen worden aangemaakt of gekoppeld.</p><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bkp-project-mapping')).'">Koppelassistent openen</a></p></section>';
    echo '</div>';
}

function bkp_admin_render_grid_row($entity,$config,$post,$index) {
    $id=$post?$post->ID:0; $source=$post&&get_post_meta($id,'_bkp_imported',true)==='1'?'Excel r. '.get_post_meta($id,'_bkp_source_row',true):'Handmatig';
    $search=$post?$post->post_title.' '.bkp_project_name(bkp_get_project_id($id),'').' '.get_post_meta($id,'_bkp_main_topic',true).' '.get_post_meta($id,'_bkp_responsible',true).' '.implode(' ',bkp_linked_user_names($id)).' '.get_post_meta($id,'_bkp_location',true):'';
    $sort_order=$post?get_post_meta($id,'_bkp_sort_order',true):'';
    echo '<tr class="bkp-grid-row" data-search="'.esc_attr(strtolower($search)).'">';
    if($entity==='project') echo '<td class="bkp-order-cell"><button type="button" class="button-link bkp-drag-handle" title="Sleep om te verplaatsen" aria-label="Sleep om te verplaatsen"><span class="dashicons dashicons-move"></span></button><span class="bkp-order-buttons"><button type="button" class="button-link bkp-move-up" title="Omhoog" aria-label="Omhoog"><span class="dashicons dashicons-arrow-up-alt2"></span></button><button type="button" class="button-link bkp-move-down" title="Omlaag" aria-label="Omlaag"><span class="dashicons dashicons-arrow-down-alt2"></span></button></span><input class="bkp-sort-order-input" type="hidden" name="rows['.esc_attr($index).'][sort_order]" value="'.esc_attr($sort_order!==''?$sort_order:$index).'" /></td>';
    echo '<td class="bkp-row-number"><span>'.($post?esc_html((string)($index+1)):'nieuw').'</span><input type="hidden" name="rows['.esc_attr($index).'][id]" value="'.esc_attr($id).'"></td>';
    foreach($config['fields'] as $key=>$field) {
        if($field['type']==='months') {
            $selected=$post?array_filter(explode(',',(string)get_post_meta($id,$field['meta'],true))):array();
            foreach(bkp_month_definitions() as $month=>$label) echo '<td class="bkp-month-check"><label title="'.esc_attr($label).'"><input type="checkbox" name="rows['.esc_attr($index).'][months]['.esc_attr($month).']" value="1" '.checked(in_array($month,$selected,true),true,false).'><span></span></label></td>';
            continue;
        }
        $value=$key==='title'?($post?$post->post_title:''):($post&&$field['meta']!==''?get_post_meta($id,$field['meta'],true):'');
        echo '<td class="'.(!empty($field['wide'])?'bkp-col-wide':'').($field['type']==='users'?' bkp-col-users':'').'">';
        $name='rows['.$index.']['.$key.']';
        if($field['type']==='inventory_responses') bkp_inventory_render_admin_summary_cell($id);
        elseif($field['type']==='users') bkp_render_user_picker($name,$post?bkp_get_linked_user_ids($id):array(),'bkp-user-picker--grid');
        elseif($field['type']==='checkbox') echo '<label class="bkp-switch"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked((string)$value,'1',false).'><span></span></label>';
        elseif($field['type']==='project') bkp_render_project_select($name,absint($value),'bkp-project-select--grid');
        elseif($field['type']==='status') bkp_render_status_select($name,$field['status_context']??'',(string)$value);
        elseif($field['type']==='hidden_text') echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'"><span class="bkp-muted-label">'.esc_html($value?:'—').'</span>';
        elseif($field['type']==='textarea') echo '<textarea name="'.esc_attr($name).'" rows="2">'.esc_textarea($value).'</textarea>';
        elseif($field['type']==='topic') echo '<input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" list="bkp-todo-main-topics" placeholder="Kies of typ nieuw">';
        else echo '<input type="'.esc_attr($field['type']).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
        echo '</td>';
    }
    echo '<td class="bkp-source">'.esc_html($source).'</td><td class="bkp-delete-cell"><label><input class="bkp-delete-row" type="checkbox" name="rows['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td></tr>';
}

function bkp_admin_project_mapping_page() {
    if(!current_user_can('manage_options')) return;
    $candidates=bkp_project_migration_candidates();
    $projects=bkp_projects(true);
    echo '<div class="wrap bkp-admin-wrap"><h1>Projectkoppelingen controleren</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Deze assistent doet niets automatisch. Alleen aangevinkte hoofdonderwerpen worden na bevestiging aan een bestaand of nieuw project gekoppeld. Het oude tekstveld blijft als naslag bestaan.</p>';
    echo '<div class="notice notice-info inline"><p>Activiteiten, projectplanning, inventarisaties, acties en documenten kun je daarna rechtstreeks via hun grids aan een project koppelen.</p></div>';
    if(!$candidates) { echo '<section class="bkp-admin-panel"><p>Er zijn geen ongekoppelde to-do’s met een oud hoofdonderwerp gevonden.</p><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bkp-projects')).'">Terug naar Projecten</a></p></section></div>'; return; }
    echo '<form method="post">'; wp_nonce_field('bkp_apply_project_mapping'); echo '<input type="hidden" name="bkp_admin_action" value="apply_project_mapping">';
    echo '<div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid"><thead><tr><th>Meenemen</th><th>Oud hoofdonderwerp</th><th>To-do’s</th><th>Koppelen aan</th></tr></thead><tbody>';
    foreach($candidates as $key=>$candidate) {
        $existing=bkp_find_project_by_title($candidate['name']);
        echo '<tr><td><label class="bkp-switch"><input type="checkbox" name="topics['.esc_attr($key).'][include]" value="1"><span></span></label></td><td><strong>'.esc_html($candidate['name']).'</strong></td><td>'.esc_html(count($candidate['items'])).'</td><td><select name="topics['.esc_attr($key).'][project_id]"><option value="0">'.($existing?'Bestaand gelijknamig project gebruiken':'Nieuw project aanmaken').'</option>';
        foreach($projects as $project) echo '<option value="'.esc_attr($project->ID).'" '.selected($existing,$project->ID,false).'>'.esc_html($project->post_title).'</option>';
        echo '</select></td></tr>';
    }
    echo '</tbody></table></div><div class="bkp-save-bar"><span>Niet aangevinkte regels blijven ongewijzigd.</span><button class="button button-primary" type="submit">Geselecteerde koppelingen toepassen</button></div></form></div>';
}

function bkp_admin_committees_page() {
    if(!current_user_can('edit_posts')) return;
    $rows=bkp_committees();
    $groups=array(); $committee_names=array();
    foreach($rows as $index=>$row) {
        $committee=trim((string)($row['committee']??''));
        if($committee!=='') $committee_names[$committee]=$committee;
        if(!isset($groups[$committee])) $groups[$committee]=array();
        $groups[$committee][]=array('index'=>$index,'row'=>$row);
    }
    if($groups) uksort($groups,'strnatcasecmp');
    if($committee_names) natcasesort($committee_names);
    $has_committees=!empty($committee_names);

    echo '<div class="wrap bkp-admin-wrap bkp-committees-admin"><h1>Commissies</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Voeg snel een persoon toe en kies daarbij een bestaande commissie of maak direct een nieuwe commissie. In de overzichten eronder wijzig of verwijder je alleen de namen.</p>';

    echo '<section class="bkp-admin-panel bkp-committee-quick"><h2>Persoon toevoegen</h2><form method="post" class="bkp-committee-add-form">';
    wp_nonce_field('bkp_add_committee_member');
    echo '<input type="hidden" name="bkp_admin_action" value="add_committee_member"><div class="bkp-committee-add-grid">';
    echo '<label><span>Naam</span><input type="text" name="member_name" required autocomplete="off" placeholder="Voor- en achternaam"></label>';
    echo '<label><span>Kies commissie</span><select name="committee_choice" required data-bkp-committee-choice>';
    if($has_committees) echo '<option value="">Kies een bestaande commissie</option>';
    foreach($committee_names as $committee_name) echo '<option value="'.esc_attr($committee_name).'">'.esc_html($committee_name).'</option>';
    echo '<option value="__new__" '.selected(!$has_committees,true,false).'>+ Nieuwe commissie</option></select></label>';
    echo '<label class="bkp-new-committee-field" data-bkp-new-committee '.($has_committees?'hidden':'').'><span>Naam nieuwe commissie</span><input type="text" name="new_committee" placeholder="Bijvoorbeeld Jeugdcommissie"></label>';
    echo '<div class="bkp-committee-add-submit"><button type="submit" class="button button-primary">Persoon toevoegen</button></div></div></form></section>';

    echo '<section class="bkp-admin-panel"><div class="bkp-grid-toolbar"><div><h2>Bestaande commissies</h2><p class="description">Pas namen direct aan of vink verwijderen aan. Alle wijzigingen worden samen opgeslagen.</p></div>';
    if($groups) echo '<input type="search" class="bkp-grid-search" data-bkp-committee-search placeholder="Zoek commissie of naam…">';
    echo '<span class="bkp-grid-count">'.count($committee_names).' commissies · '.count($rows).' personen</span></div>';

    if(!$groups) {
        echo '<div class="notice notice-info inline"><p>Er zijn nog geen commissies. Voeg hierboven de eerste persoon toe en kies <strong>Nieuwe commissie</strong>.</p></div></section></div>';
        return;
    }

    echo '<form method="post" class="bkp-grid-form bkp-committee-members-form">';
    wp_nonce_field('bkp_save_committee_members');
    echo '<input type="hidden" name="bkp_admin_action" value="save_committee_members"><div class="bkp-committee-sections">';
    foreach($groups as $committee=>$members) {
        $label=$committee!==''?$committee:'Zonder commissie';
        $search=$label;
        foreach($members as $member_entry) $search.=' '.($member_entry['row']['name']??'');
        echo '<article class="bkp-committee-panel" data-bkp-committee-panel data-search="'.esc_attr(strtolower($search)).'">';
        echo '<header class="bkp-committee-panel-header"><div><h3>'.esc_html($label).'</h3><span>'.count($members).' '.(count($members)===1?'persoon':'personen').'</span></div>';
        if($committee!=='') echo '<button type="submit" class="button-link-delete bkp-confirm-delete-committee" name="delete_committee" value="'.esc_attr($committee).'" formnovalidate>Hele commissie verwijderen</button>';
        echo '</header><div class="bkp-simple-members">';
        foreach($members as $member_entry) {
            $index=(int)$member_entry['index']; $row=$member_entry['row'];
            echo '<div class="bkp-simple-member-row">';
            echo '<input type="hidden" name="members['.esc_attr($index).'][committee]" value="'.esc_attr($committee).'">';
            echo '<label><span class="screen-reader-text">Naam</span><input type="text" name="members['.esc_attr($index).'][name]" value="'.esc_attr($row['name']??'').'" placeholder="Naam"></label>';
            echo '<label class="bkp-simple-delete"><input class="bkp-committee-delete-member" type="checkbox" name="members['.esc_attr($index).'][delete]" value="1"> verwijderen</label>';
            echo '</div>';
        }
        echo '</div></article>';
    }
    echo '</div><div class="bkp-save-bar"><span>Deze thema-update importeert of wijzigt geen bestaande planningdata.</span>';
    submit_button('Namen opslaan','primary','submit',false);
    echo '</div></form></section></div>';
}

function bkp_admin_documents_page() {
    if(!current_user_can('edit_posts')) return;
    $documents=bkp_documents(false);
    echo '<div class="wrap bkp-admin-wrap"><h1>Documenten</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Upload bestanden die leden via de afgeschermde planning kunnen downloaden. Een document kan aan een project worden gekoppeld, maar blijft ook via Documenten bereikbaar.</p>';
    if(current_user_can('upload_files')) {
        echo '<section class="bkp-admin-panel"><h2>Nieuw document</h2><form method="post" enctype="multipart/form-data">'; wp_nonce_field('bkp_add_document');
        echo '<input type="hidden" name="bkp_admin_action" value="add_document"><div class="bkp-document-upload-grid"><label><span>Bestand</span><input type="file" name="document_file" required></label><label><span>Project</span>';
        bkp_render_project_select('document_project_id',0);
        echo '</label><label><span>Titel</span><input type="text" name="document_title" placeholder="Leeg = bestandsnaam"></label><label class="bkp-span-2"><span>Beschrijving</span><textarea rows="3" name="document_description" placeholder="Korte toelichting voor bezoekers"></textarea></label><label class="bkp-document-visible"><input type="checkbox" name="document_visible" value="1" checked> Direct zichtbaar op de website</label><div><button type="submit" class="button button-primary">Document uploaden</button></div></div></form></section>';
    }
    echo '<section class="bkp-admin-panel"><h2>Beschikbare documenten</h2>';
    if(!$documents) {
        echo '<div class="notice notice-info inline"><p>Er zijn nog geen documenten toegevoegd.</p></div></section></div>'; return;
    }
    echo '<form method="post" class="bkp-grid-form">'; wp_nonce_field('bkp_save_documents'); echo '<input type="hidden" name="bkp_admin_action" value="save_documents"><div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid bkp-documents-table"><thead><tr><th>Volgorde</th><th>Project</th><th>Titel</th><th>Bestand</th><th>Beschrijving</th><th>Zichtbaar</th><th>Verwijderen</th></tr></thead><tbody>';
    foreach($documents as $document) {
        $id=$document['id'];
        echo '<tr><td><input class="small-text" type="number" name="documents['.esc_attr($id).'][sort_order]" value="'.esc_attr($document['sort_order']).'"></td><td>';
        bkp_render_project_select('documents['.$id.'][project_id]',absint($document['project_id']??0));
        echo '</td><td class="bkp-col-wide"><input type="text" name="documents['.esc_attr($id).'][title]" value="'.esc_attr($document['title']).'"></td><td><strong>'.esc_html($document['original_name']).'</strong><br><span class="description">'.esc_html(size_format((int)$document['size'])).'</span><br><a href="'.esc_url(bkp_document_download_url($document)).'">Download testen</a></td><td class="bkp-col-wide"><textarea rows="3" name="documents['.esc_attr($id).'][description]">'.esc_textarea($document['description']).'</textarea></td><td><label class="bkp-switch"><input type="checkbox" name="documents['.esc_attr($id).'][visible]" value="1" '.checked($document['visible'],'1',false).'><span></span></label></td><td><label><input class="bkp-delete-row" type="checkbox" name="documents['.esc_attr($id).'][delete]" value="1"> verwijderen</label></td></tr>';
    }
    echo '</tbody></table></div><div class="bkp-save-bar"><span>Bestanden worden alleen verwijderd wanneer je dit aanvinkt en opslaat.</span>'; submit_button('Documenten opslaan','primary','submit',false); echo '</div></form></section></div>';
}

function bkp_admin_principles_page() {
    if(!current_user_can('edit_posts')) return; $rows=(array)get_option('bkp_principles',array());
    echo '<div class="wrap bkp-admin-wrap"><h1>Uitgangspunten</h1>'; bkp_admin_show_notice(); echo '<p class="bkp-admin-lead">Bewerk uitgangspunten en controlepunten in één grid. Dit onderdeel is uitsluitend zichtbaar in de WordPress-backend.</p><div class="bkp-grid-toolbar"><input type="search" class="bkp-grid-search" placeholder="Filter regels…"><button type="button" class="button bkp-add-row">Nieuwe regel</button></div><form method="post" class="bkp-grid-form">'; wp_nonce_field('bkp_save_principles'); echo '<input type="hidden" name="bkp_admin_action" value="save_principles"><div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid"><thead><tr><th>#</th><th>Onderdeel</th><th>Uitgangspunt / controlepunt</th><th>Waarde / voorgestelde datum</th><th>Status / toelichting</th><th>Bron</th><th>Verwijderen</th></tr></thead><tbody class="bkp-grid-body">';
    $i=0; foreach($rows as $row) bkp_admin_render_principle_row($row,$i++); echo '</tbody></table></div><template class="bkp-row-template">'; bkp_admin_render_principle_row(array(),'__INDEX__'); echo '</template><div class="bkp-save-bar"><span>Wijzigingen worden pas toegepast na opslaan.</span>'; submit_button('Uitgangspunten opslaan','primary','submit',false); echo '</div></form></div>';
}
function bkp_admin_render_principle_row($row,$index) {
    $section=$row['section']??'Uitgangspunten'; $source=!empty($row['source_key'])?'Excel r. '.($row['source_row']??''):'Handmatig';
    echo '<tr class="bkp-grid-row" data-search="'.esc_attr(strtolower(($row['title']??'').' '.($row['note']??''))).'"><td>'.($index==='__INDEX__'?'nieuw':esc_html((string)($index+1))).'<input type="hidden" name="principles['.esc_attr($index).'][source_key]" value="'.esc_attr($row['source_key']??'').'"><input type="hidden" name="principles['.esc_attr($index).'][source_row]" value="'.esc_attr($row['source_row']??'').'"></td><td><select name="principles['.esc_attr($index).'][section]"><option '.selected($section,'Uitgangspunten',false).'>Uitgangspunten</option><option '.selected($section,'Te controleren',false).'>Te controleren</option></select></td><td class="bkp-col-wide"><input name="principles['.esc_attr($index).'][title]" value="'.esc_attr($row['title']??'').'"></td><td><input name="principles['.esc_attr($index).'][value]" value="'.esc_attr($row['value']??'').'"></td><td class="bkp-col-wide"><textarea rows="2" name="principles['.esc_attr($index).'][note]">'.esc_textarea($row['note']??'').'</textarea></td><td class="bkp-source">'.esc_html($source).'</td><td><label><input class="bkp-delete-row" type="checkbox" name="principles['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td></tr>';
}

function bkp_admin_board_page() {
    if(!current_user_can('manage_options')) return; $board=bkp_normalise_board(get_option('bkp_board',array())); $contact=(array)get_option('bkp_contact_details',array());
    echo '<div class="wrap bkp-admin-wrap"><h1>Bestuur en contact</h1>'; bkp_admin_show_notice(); echo '<p class="bkp-admin-lead">Hier wijzig je bestuursnamen, functies, telefoonnummers, e-mailadressen en algemene contactgegevens.</p><form method="post" class="bkp-grid-form">'; wp_nonce_field('bkp_save_board'); echo '<input type="hidden" name="bkp_admin_action" value="save_board"><section class="bkp-admin-panel"><div class="bkp-grid-toolbar"><h2>Bestuur</h2><button type="button" class="button bkp-add-row">Nieuw bestuurslid</button></div><div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid"><thead><tr><th>Functie / rol</th><th>Naam / namen</th><th>Telefoon</th><th>E-mail</th><th>Notities</th><th>Verwijderen</th></tr></thead><tbody class="bkp-grid-body">';
    $i=0; foreach($board as $row) bkp_admin_render_board_row($row,$i++); echo '</tbody></table></div><template class="bkp-row-template">'; bkp_admin_render_board_row(array(),'__INDEX__'); echo '</template></section><section class="bkp-admin-panel"><h2>Algemene contactgegevens</h2><div class="bkp-contact-form"><label>Contactpersoon<input name="contact_name" value="'.esc_attr($contact['contact_name']??'').'"></label><label>Telefoon<input name="contact_phone" value="'.esc_attr($contact['phone']??'').'"></label><label>E-mail<input type="email" name="contact_email" value="'.esc_attr($contact['email']??'').'"></label><label>Website<input type="url" name="contact_website" value="'.esc_attr($contact['website']??'').'"></label><label class="bkp-span-2">Adres<textarea rows="2" name="contact_address">'.esc_textarea($contact['address']??'').'</textarea></label><label class="bkp-span-2">Toelichting op de website<textarea rows="4" name="contact_notes">'.esc_textarea($contact['notes']??get_option('bkp_contact','')).'</textarea></label></div></section><div class="bkp-save-bar"><span>Deze gegevens staan op het tabblad Info van de planning.</span>'; submit_button('Bestuur en contact opslaan','primary','submit',false); echo '</div></form></div>';
}
function bkp_admin_render_board_row($row,$index) {
    echo '<tr class="bkp-grid-row"><td><input name="board['.esc_attr($index).'][role]" value="'.esc_attr($row['role']??'').'"></td><td class="bkp-col-wide"><input name="board['.esc_attr($index).'][name]" value="'.esc_attr($row['name']??'').'"></td><td><input type="tel" name="board['.esc_attr($index).'][phone]" value="'.esc_attr($row['phone']??'').'"></td><td><input type="email" name="board['.esc_attr($index).'][email]" value="'.esc_attr($row['email']??'').'"></td><td class="bkp-col-wide"><textarea rows="2" name="board['.esc_attr($index).'][notes]">'.esc_textarea($row['notes']??'').'</textarea></td><td><label><input class="bkp-delete-row" type="checkbox" name="board['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td></tr>';
}


function bkp_admin_editors_page() {
    if(!current_user_can('manage_options')) return;
    $sections=bkp_frontend_sections();
    $permissions=bkp_frontend_permissions();
    $users=get_users(array('orderby'=>'display_name','order'=>'ASC'));
    echo '<div class="wrap bkp-admin-wrap bkp-editors-admin"><h1>Frontend bewerkers</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Geef per WordPress-gebruiker aan welke onderdelen die persoon op de voorkant mag wijzigen. Meerdere personen kunnen hetzelfde onderdeel beheren. Iedere ingelogde gebruiker kan alles bekijken. Alleen toegekende onderdelen kunnen worden gewijzigd; bezoekers met het algemene kijkwachtwoord blijven alleen-lezen.</p>';
    echo '<div class="notice notice-info inline"><p>Maak zo nodig eerst een persoonlijk account via <a href="'.esc_url(admin_url('user-new.php')).'"><strong>Gebruikers → Nieuwe gebruiker</strong></a>. De rol <strong>Abonnee</strong> is voldoende; de rechten hieronder bepalen wat iemand in de planning mag wijzigen. Beheerders hebben automatisch toegang tot alle onderdelen.</p></div>';
    if(!$users) { echo '<div class="notice notice-warning inline"><p>Er zijn nog geen gebruikers beschikbaar.</p></div></div>'; return; }
    echo '<form method="post" class="bkp-grid-form">'; wp_nonce_field('bkp_save_frontend_permissions');
    echo '<input type="hidden" name="bkp_admin_action" value="save_frontend_permissions"><div class="bkp-grid-scroll"><table class="widefat striped bkp-editors-grid"><thead><tr><th>Gebruiker</th><th>Rol</th><th class="bkp-editor-all-column">Alles</th>';
    foreach($sections as $section) echo '<th>'.esc_html($section['label']).'</th>';
    echo '</tr></thead><tbody>';
    foreach($users as $user) {
        $automatic=user_can($user,'manage_options');
        $assigned=$automatic?array_keys($sections):($permissions[$user->ID]??array());
        echo '<tr data-bkp-editor-row><td><strong>'.esc_html($user->display_name ?: $user->user_login).'</strong><br><code>'.esc_html($user->user_login).'</code>'; if($user->user_email) echo '<br><span class="description">'.esc_html($user->user_email).'</span>'; echo '</td>';
        echo '<td>'.esc_html(implode(', ',array_map(function($role){$obj=get_role($role);return $obj&&isset($obj->name)?translate_user_role($obj->name):$role;},$user->roles))).($automatic?'<br><span class="bkp-auto-access">automatisch alle rechten</span>':'').'</td>';
        echo '<td class="bkp-editor-check"><input type="checkbox" data-bkp-editor-all '.checked(count($assigned),count($sections),false).' '.disabled($automatic,true,false).'></td>';
        foreach($sections as $key=>$section) echo '<td class="bkp-editor-check"><input type="checkbox" data-bkp-editor-section name="permissions['.esc_attr($user->ID).'][]" value="'.esc_attr($key).'" '.checked(in_array($key,$assigned,true),true,false).' '.disabled($automatic,true,false).'></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div><div class="bkp-save-bar"><span>Deze instelling verandert geen bestaande planningdata.</span>'; submit_button('Bewerkrechten opslaan','primary','submit',false); echo '</div></form>';
    echo '<script>document.addEventListener("change",function(e){if(!e.target.matches("[data-bkp-editor-all]"))return;const row=e.target.closest("[data-bkp-editor-row]");row?.querySelectorAll("[data-bkp-editor-section]").forEach(function(box){box.checked=e.target.checked;});});document.addEventListener("change",function(e){if(!e.target.matches("[data-bkp-editor-section]"))return;const row=e.target.closest("[data-bkp-editor-row]");const boxes=[...row.querySelectorAll("[data-bkp-editor-section]")];const all=row.querySelector("[data-bkp-editor-all]");if(all)all.checked=boxes.length>0&&boxes.every(function(box){return box.checked;});});</script>';
    echo '</div>';
}

function bkp_admin_hub_page($title, $intro, $cards) {
    if(!current_user_can('edit_posts')) return;
    echo '<div class="wrap bkp-admin-wrap"><h1>'.esc_html($title).'</h1><p class="bkp-admin-lead">'.esc_html($intro).'</p><div class="bkp-admin-cards">';
    foreach($cards as $card) {
        $count = isset($card['count']) ? (int) $card['count'] : 0;
        echo '<a class="bkp-admin-card" href="'.esc_url(admin_url('admin.php?page='.$card['page'])).'"><strong>'.esc_html($count).'</strong><span>'.esc_html($card['label']).'</span><small>'.esc_html($card['description']).'</small></a>';
    }
    echo '</div><p><a class="button" href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">Frontend bekijken</a></p></div>';
}

function bkp_admin_planning_hub_page() {
    bkp_admin_hub_page('Planning','Projecten vormen de kapstok voor de kalender, planningstaken en overige werkonderdelen.',array(
        array('label'=>'Projecten','page'=>'bkp-projects','count'=>count(bkp_projects(true)),'description'=>'Centrale projecten, projectleiders, status en voortgang.'),
        array('label'=>'Jaarplanning','page'=>'bkp-events','count'=>count(bkp_events()),'description'=>'Activiteiten, data, locaties en gekoppelde gebruikers.'),
        array('label'=>'Projectplanning','page'=>'bkp-project','count'=>count(bkp_tasks('Projectplanning')),'description'=>'Projecttaken, maanden, status en eigenaren.'),
    ));
}

function bkp_admin_tasks_hub_page() {
    bkp_admin_hub_page('Taken','Beheer werkpunten en opvolging zonder door alle planningonderdelen te hoeven zoeken.',array(
        array('label'=>'To-do','page'=>'bkp-todos','count'=>count(bkp_tasks('To-do')),'description'=>'Taken per hoofdonderwerp met deadline en status.'),
        array('label'=>'Inventarisaties','page'=>'bkp-inventories','count'=>count(bkp_inventories()),'description'=>'Naam en aantal verzamelen, totalen bekijken en exporteren naar Excel.'),
        array('label'=>'Acties & besluiten','page'=>'bkp-actions','count'=>count(bkp_actions()),'description'=>'Acties, besluiten, einddata en afhandeling.'),
    ));
}

function bkp_admin_organisation_hub_page() {
    bkp_admin_hub_page('Organisatie','Personen, commissies en interne uitgangspunten staan in één logisch onderdeel.',array(
        array('label'=>'Commissies','page'=>'bkp-committees','count'=>count(bkp_committees()),'description'=>'Commissies en de bijbehorende personen.'),
        array('label'=>'Bestuur & contact','page'=>'bkp-board','count'=>count(bkp_normalise_board(get_option('bkp_board',array()))),'description'=>'Bestuursleden, telefoonnummers en contactgegevens.'),
        array('label'=>'Uitgangspunten','page'=>'bkp-principles','count'=>count((array)get_option('bkp_principles',array())),'description'=>'Interne regels en controlepunten; alleen in de backend.'),
    ));
}

function bkp_admin_settings_page() {
    if(!current_user_can('manage_options')) return;
    $configured=(bool)get_option('bkp_access_hash');
    echo '<div class="wrap bkp-admin-wrap"><h1>Instellingen</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Algemene instellingen van de afgeschermde jaarplanner. Deze pagina importeert of wijzigt geen planningregels.</p><section class="bkp-admin-panel">';
    echo '<form method="post">'; wp_nonce_field('bkp_save_settings'); echo '<input type="hidden" name="bkp_admin_action" value="save_settings"><table class="form-table"><tr><th>Beveiliging</th><td><strong>'.($configured?'Algemeen kijkwachtwoord ingesteld':'Nog geen algemeen kijkwachtwoord ingesteld').'</strong></td></tr><tr><th><label for="access_password">Nieuw algemeen kijkwachtwoord</label></th><td><input class="regular-text" type="password" id="access_password" name="access_password" autocomplete="new-password"><p class="description">Leeg laten om het huidige algemene kijkwachtwoord te behouden.</p></td></tr><tr><th><label for="season">Seizoen</label></th><td><input class="regular-text" id="season" name="season" value="'.esc_attr(get_option('bkp_season','2026-2027')).'"></td></tr><tr><th><label for="carnival_period">Carnavalperiode</label></th><td><input class="regular-text" id="carnival_period" name="carnival_period" value="'.esc_attr(get_option('bkp_carnival_period','')).'"></td></tr><tr><th><label for="intro_text">Introtekst</label></th><td><textarea class="large-text" rows="4" id="intro_text" name="intro_text">'.esc_textarea(get_option('bkp_intro_text','Alle activiteiten, taken, inventarisaties en aandachtspunten voor het carnavalsseizoen op één plek.')).'</textarea></td></tr></table>'; submit_button('Instellingen opslaan'); echo '</form></section></div>';
}
