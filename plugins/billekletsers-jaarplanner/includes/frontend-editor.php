<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Frontend editing is deliberately kept separate from the shared planning
 * password. Visitors with the shared password remain read-only. A person must
 * sign in with an individual WordPress account and be assigned one or more
 * sections by an administrator.
 */
function bkp_frontend_sections() {
    return array(
        'projects' => array('label'=>'Projecten','tab'=>'projects','description'=>'Projecten, projectleiders, status, looptijd en gekoppelde projectleden.'),
        'events' => array('label'=>'Jaarplanning','tab'=>'agenda','description'=>'Activiteiten, datum, tijd, locatie, verantwoordelijken en publicatie op de openbare website.'),
        'project' => array('label'=>'Projectplanning','tab'=>'project','description'=>'Projecttaken, actieve maanden, status en verantwoordelijken.'),
        'inventories' => array('label'=>'Inventarisaties','tab'=>'inventory','description'=>'Eenvoudige inventarisaties met naam, aantal, sluitdatum en verantwoordelijken.'),
        'todos' => array('label'=>'To-do','tab'=>'todo','description'=>'Werkpunten per project, met deadlines, status en verantwoordelijken.'),
        'actions' => array('label'=>'Acties en besluiten','tab'=>'actions','description'=>'Acties, besluiten, eigenaren, einddata en notities.'),
        'committees' => array('label'=>'Commissies','tab'=>'committees','description'=>'Commissies en de namen van de commissieleden.'),
        'documents' => array('label'=>'Documenten','tab'=>'documents','description'=>'Documenten uploaden, zichtbaar maken en verwijderen.'),
        'board' => array('label'=>'Bestuur en contact','tab'=>'info','description'=>'Bestuursleden en algemene contactgegevens.'),
    );
}

function bkp_frontend_permissions() {
    $stored = get_option('bkp_frontend_permissions', array());
    $allowed = array_keys(bkp_frontend_sections());
    $out = array();
    foreach ((array) $stored as $user_id=>$sections) {
        $user_id = absint($user_id);
        if (!$user_id) continue;
        $clean = array_values(array_intersect($allowed, array_map('sanitize_key', (array) $sections)));
        if ($clean) $out[$user_id] = $clean;
    }
    return $out;
}

function bkp_user_frontend_sections($user_id=0) {
    $user_id = $user_id ? absint($user_id) : get_current_user_id();
    if (!$user_id) return array();
    $user = get_userdata($user_id);
    if (!$user) return array();
    if (user_can($user, 'manage_options')) return array_keys(bkp_frontend_sections());
    $permissions = bkp_frontend_permissions();
    return $permissions[$user_id] ?? array();
}

function bkp_user_can_frontend_edit($section, $user_id=0) {
    $section = sanitize_key($section);
    return in_array($section, bkp_user_frontend_sections($user_id), true);
}

function bkp_frontend_editor_url($section='dashboard', $args=array()) {
    $query = array_merge(array('bkp_edit'=>sanitize_key($section ?: 'dashboard')), (array) $args);
    return add_query_arg($query, home_url('/'));
}

function bkp_frontend_edit_button($section, $label='Onderdeel bewerken') {
    if (!bkp_user_can_frontend_edit($section)) return;
    echo '<a class="bkp-btn bkp-btn--light bkp-panel-edit" href="'.esc_url(bkp_frontend_editor_url($section)).'">'.esc_html($label).'</a>';
}

add_filter('show_admin_bar', function($show) {
    if (is_user_logged_in() && !current_user_can('edit_posts')) return false;
    return $show;
});

function bkp_front_posts_for_entity($entity) {
    switch ($entity) {
        case 'projects': return bkp_projects(true);
        case 'events': return bkp_events();
        case 'project': return bkp_tasks('Projectplanning');
        case 'inventories': return bkp_inventories();
        case 'todos': return bkp_tasks('To-do');
        case 'actions': return bkp_actions();
    }
    return array();
}

function bkp_front_entity_matches($post_id, $config) {
    if (!$post_id || get_post_type($post_id) !== $config['post_type']) return false;
    if (!$config['category']) return true;
    return (string) get_post_meta($post_id, '_bkp_category', true) === (string) $config['category'];
}

function bkp_front_save_grid($entity, $config) {
    $rows = isset($_POST['rows']) ? (array) wp_unslash($_POST['rows']) : array();
    $saved = 0;
    $position = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $position++;
        $id = absint($row['id'] ?? 0);
        if ($id && !bkp_front_entity_matches($id, $config)) continue;

        if (!empty($row['delete'])) {
            if ($id) { if ($entity==='projects' && bkp_project_link_count($id)>0) continue; wp_delete_post($id, true); }
            continue;
        }

        $title = sanitize_text_field($row['title'] ?? '');
        if ($title === '') continue;
        $postarr = array(
            'post_type'=>$config['post_type'],
            'post_status'=>'publish',
            'post_title'=>$title,
        );
        if ($id) $postarr['ID'] = $id;
        else $postarr['post_author'] = get_current_user_id();

        $post_id = $id ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
        if (is_wp_error($post_id) || !$post_id) continue;

        if (!$id) {
            delete_post_meta($post_id, '_bkp_imported');
            delete_post_meta($post_id, '_bkp_source_key');
            update_post_meta($post_id, '_bkp_source_row', 100000 + $post_id);
        }
        if ($config['category']) update_post_meta($post_id, '_bkp_category', $config['category']);
        if ($entity==='projects' && trim((string)get_post_meta($post_id,'_bkp_project_season',true))==='') update_post_meta($post_id,'_bkp_project_season',sanitize_text_field((string)get_option('bkp_season','')));

        foreach ($config['fields'] as $key=>$field) {
            if ($key === 'title' || in_array($field['type'], array('months','inventory_responses'), true)) continue;
            $meta = $field['meta'];
            if ($field['type'] === 'users') {
                $value = bkp_sanitise_linked_user_ids($row[$key] ?? array());
                if ($value) update_post_meta($post_id, $meta, $value);
                else delete_post_meta($post_id, $meta);
                continue;
            }
            if ($field['type'] === 'project') {
                $value = absint($row[$key] ?? 0);
                if ($value && bkp_project_id_is_valid($value)) update_post_meta($post_id, $meta, $value);
                else delete_post_meta($post_id, $meta);
                continue;
            }
            if ($field['type'] === 'status') $value = bkp_sanitise_status($field['status_context'] ?? '', $row[$key] ?? '', $id ? get_post_meta($post_id, $meta, true) : '');
            elseif ($field['type'] === 'checkbox') $value = !empty($row[$key]) ? '1' : '0';
            elseif ($field['type'] === 'textarea') $value = sanitize_textarea_field($row[$key] ?? '');
            elseif ($field['type'] === 'url') $value = esc_url_raw($row[$key] ?? '', array('http','https'));
            elseif ($field['type'] === 'email') $value = sanitize_email($row[$key] ?? '');
            else $value = sanitize_text_field($row[$key] ?? '');
            update_post_meta($post_id, $meta, $value);
        }

        if (isset($config['fields']['months'])) {
            $allowed = array_keys(bkp_month_definitions());
            $selected = array();
            foreach ((array) ($row['months'] ?? array()) as $month=>$checked) {
                $month = sanitize_text_field($month);
                if ($checked && in_array($month, $allowed, true)) $selected[] = $month;
            }
            update_post_meta($post_id, '_bkp_months', implode(',', $selected));
            update_post_meta($post_id, '_bkp_period', bkp_admin_month_period($selected));
            if (trim((string) get_post_meta($post_id, '_bkp_task_status', true)) === '') {
                update_post_meta($post_id, '_bkp_task_status', $selected ? 'Gepland' : 'Nog in te plannen');
            }
        }

        if ($entity === 'project') {
            update_post_meta($post_id, '_bkp_sort_order', max(0, absint($row['sort_order'] ?? ($position - 1))));
        }
        update_post_meta($post_id, '_bkp_last_front_editor', get_current_user_id());
        update_post_meta($post_id, '_bkp_last_front_edit_at', current_time('mysql'));
        $saved++;
    }
    return $saved;
}

function bkp_front_save_committees() {
    $current = bkp_committees();
    $posted = isset($_POST['members']) ? (array) wp_unslash($_POST['members']) : array();
    $out = array();
    foreach ($posted as $key=>$entry) {
        if (!is_array($entry) || !empty($entry['delete'])) continue;
        $name = sanitize_text_field($entry['name'] ?? '');
        $committee = sanitize_text_field($entry['committee'] ?? '');
        if ($name === '' || $committee === '') continue;
        $base = array();
        if (ctype_digit((string) $key) && isset($current[(int) $key])) $base = $current[(int) $key];
        $base['name'] = $name;
        $base['committee'] = $committee;
        $base['visible'] = '1';
        $out[] = $base;
    }

    $new_name = sanitize_text_field(wp_unslash($_POST['new_member_name'] ?? ''));
    $choice = sanitize_text_field(wp_unslash($_POST['new_member_committee'] ?? ''));
    $new_committee = sanitize_text_field(wp_unslash($_POST['new_committee_name'] ?? ''));
    $committee = $choice === '__new__' ? $new_committee : $choice;
    if ($new_name !== '' && $committee !== '') {
        $out[] = array('committee'=>$committee,'name'=>$new_name,'role'=>'','phone'=>'','email'=>'','notes'=>'','visible'=>'1');
    }
    update_option('bkp_committees', bkp_normalise_committees($out), false);
    return count($out);
}

function bkp_front_save_board() {
    $rows = isset($_POST['board']) ? (array) wp_unslash($_POST['board']) : array();
    $kept = array();
    foreach ($rows as $row) if (is_array($row) && empty($row['delete'])) $kept[] = $row;
    update_option('bkp_board', bkp_normalise_board($kept), false);
    $contact = array(
        'contact_name'=>sanitize_text_field(wp_unslash($_POST['contact_name'] ?? '')),
        'phone'=>sanitize_text_field(wp_unslash($_POST['contact_phone'] ?? '')),
        'email'=>sanitize_email(wp_unslash($_POST['contact_email'] ?? '')),
        'website'=>esc_url_raw(wp_unslash($_POST['contact_website'] ?? '')),
        'address'=>sanitize_textarea_field(wp_unslash($_POST['contact_address'] ?? '')),
        'notes'=>sanitize_textarea_field(wp_unslash($_POST['contact_notes'] ?? '')),
    );
    update_option('bkp_contact_details', $contact, false);
    $summary = trim($contact['notes']);
    if (!$summary) $summary = implode("\n", array_filter(array($contact['email'], $contact['phone'], $contact['address'])));
    update_option('bkp_contact', $summary, false);
}

function bkp_front_save_documents() {
    $current = bkp_documents(false);
    $posted = isset($_POST['documents']) ? (array) wp_unslash($_POST['documents']) : array();
    $out = array();
    $storage = bkp_private_documents_storage();
    foreach ($current as $document) {
        $id = $document['id'];
        if (!isset($posted[$id]) || !is_array($posted[$id])) { $out[] = $document; continue; }
        $row = $posted[$id];
        if (!empty($row['delete'])) {
            $path = trailingslashit($storage['dir']) . basename($document['stored_name']);
            if (is_file($path)) @unlink($path);
            continue;
        }
        $document['title'] = sanitize_text_field($row['title'] ?? '') ?: $document['original_name'];
        $document['description'] = sanitize_textarea_field($row['description'] ?? '');
        $document['visible'] = !empty($row['visible']) ? '1' : '0';
        $document['sort_order'] = intval($row['sort_order'] ?? 0);
        $document['project_id'] = bkp_project_id_is_valid(absint($row['project_id'] ?? 0)) ? absint($row['project_id']) : 0;
        $out[] = $document;
    }

    if (!empty($_FILES['document_file']) && isset($_FILES['document_file']['error']) && (int) $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $original = sanitize_file_name((string) $file['name']);
        $max = min((int) wp_max_upload_size(), 50 * 1024 * 1024);
        $type = wp_check_filetype($original, get_allowed_mime_types());
        if (!empty($type['ext']) && !empty($type['type']) && (int) $file['size'] <= $max && is_uploaded_file((string) $file['tmp_name'])) {
            $storage = bkp_ensure_private_documents_dir();
            if (!is_wp_error($storage)) {
                $stored = wp_unique_filename($storage['dir'], wp_generate_password(12, false, false) . '-' . $original);
                $target = trailingslashit($storage['dir']) . $stored;
                if (@move_uploaded_file((string) $file['tmp_name'], $target)) {
                    @chmod($target, 0640);
                    $title = sanitize_text_field(wp_unslash($_POST['document_title'] ?? ''));
                    if ($title === '') $title = pathinfo($original, PATHINFO_FILENAME);
                    $out[] = array(
                        'id'=>str_replace('-', '', wp_generate_uuid4()),
                        'title'=>$title,
                        'description'=>sanitize_textarea_field(wp_unslash($_POST['document_description'] ?? '')),
                        'stored_name'=>$stored,
                        'original_name'=>$original,
                        'mime'=>$type['type'],
                        'size'=>(int) filesize($target),
                        'uploaded_at'=>current_time('mysql'),
                        'visible'=>!empty($_POST['document_visible']) ? '1' : '0',
                        'sort_order'=>count($out) + 1,
                        'project_id'=>bkp_project_id_is_valid(absint($_POST['document_project_id'] ?? 0)) ? absint($_POST['document_project_id']) : 0,
                    );
                }
            }
        }
    }
    update_option('bkp_documents', bkp_normalise_documents($out), false);
    return count($out);
}

function bkp_front_handle_save() {
    if (!is_user_logged_in()) wp_die('Log eerst in om de planning te wijzigen.', 'Geen toegang', array('response'=>403));
    $section = sanitize_key(wp_unslash($_POST['section'] ?? ''));
    if (!isset(bkp_frontend_sections()[$section]) || !bkp_user_can_frontend_edit($section)) {
        wp_die('Je hebt geen rechten voor dit onderdeel.', 'Geen toegang', array('response'=>403));
    }
    check_admin_referer('bkp_front_save_' . $section, 'bkp_front_nonce');

    $message = 'Wijzigingen opgeslagen.';
    if (in_array($section, array('projects','events','project','inventories','todos','actions'), true)) {
        $configs = bkp_admin_entity_configs();
        $count = bkp_front_save_grid($section, $configs[$section]);
        $message = $count . ' regel(s) opgeslagen.';
    } elseif ($section === 'committees') {
        $count = bkp_front_save_committees();
        $message = $count . ' commissielid/leden opgeslagen.';
    } elseif ($section === 'board') {
        bkp_front_save_board();
        $message = 'Bestuur en contactgegevens opgeslagen.';
    } elseif ($section === 'documents') {
        $count = bkp_front_save_documents();
        $message = $count . ' document(en) opgeslagen.';
    }

    wp_safe_redirect(bkp_frontend_editor_url($section, array('bkp_saved'=>'1','bkp_message'=>$message)));
    exit;
}
add_action('admin_post_bkp_front_save', 'bkp_front_handle_save');

function bkp_front_editor_navigation($active='dashboard') {
    $sections = bkp_user_frontend_sections();
    echo '<nav class="bkp-editor-nav" aria-label="Bewerkbare onderdelen">';
    echo '<a class="'.($active==='dashboard'?'is-active':'').'" href="'.esc_url(bkp_frontend_editor_url('dashboard')).'">Overzicht</a>';
    foreach (bkp_frontend_sections() as $key=>$section) {
        if (!in_array($key, $sections, true)) continue;
        echo '<a class="'.($active===$key?'is-active':'').'" href="'.esc_url(bkp_frontend_editor_url($key)).'">'.esc_html($section['label']).'</a>';
    }
    echo '</nav>';
}

function bkp_front_render_notice() {
    if (empty($_GET['bkp_saved'])) return;
    $message = sanitize_text_field(wp_unslash($_GET['bkp_message'] ?? 'Wijzigingen opgeslagen.'));
    echo '<div class="bkp-front-notice" role="status">'.esc_html($message).'</div>';
}

function bkp_front_render_editor_dashboard() {
    $sections = bkp_user_frontend_sections();
    echo '<section class="bkp-editor-heading"><div><span class="bkp-eyebrow">Frontend beheer</span><h1>Planning wijzigen</h1><p>Je ziet alleen de onderdelen waarvoor een beheerder jou rechten heeft gegeven.</p></div><a class="bkp-btn bkp-btn--light" href="'.esc_url(home_url('/')).'">Terug naar planning</a></section>';
    if (!$sections) {
        echo '<div class="bkp-empty"><p>Aan jouw account zijn geen bewerkrechten gekoppeld. Je kunt de volledige jaarplanning wel bekijken.</p><p><a class="bkp-btn" href="'.esc_url(home_url('/')).'">Naar de jaarplanning</a></p></div>';
        return;
    }
    echo '<div class="bkp-editor-cards">';
    foreach (bkp_frontend_sections() as $key=>$section) {
        if (!in_array($key, $sections, true)) continue;
        echo '<a class="bkp-card bkp-editor-card" href="'.esc_url(bkp_frontend_editor_url($key)).'"><h2>'.esc_html($section['label']).'</h2><p>'.esc_html($section['description']).'</p><span>Openen en wijzigen →</span></a>';
    }
    echo '</div>';
}

function bkp_front_render_field($field_key, $field, $index, $post=null) {
    $id = $post ? $post->ID : 0;
    $name = 'rows[' . $index . '][' . $field_key . ']';
    if ($field_key === 'title') $value = $post ? $post->post_title : '';
    else $value = $post && $field['meta'] !== '' ? get_post_meta($id, $field['meta'], true) : '';
    $class = !empty($field['wide']) ? ' bkp-front-wide' : '';
    if ($field['type'] === 'users') $class .= ' bkp-front-users-cell';
    echo '<td class="'.esc_attr(trim($class)).'">';
    if ($field_key === 'title') echo '<input type="hidden" name="rows['.esc_attr($index).'][id]" value="'.esc_attr($id).'">';
    if ($field['type'] === 'inventory_responses') {
        if ($id) {
            $stats = bkp_inventory_response_stats($id);
            echo '<div class="bkp-front-inventory-summary"><strong>'.esc_html(number_format_i18n($stats['total'])).' totaal</strong><span>'.esc_html(number_format_i18n($stats['count'])).' naam/namen</span></div>';
        } else {
            echo '<span class="bkp-muted-label">Na opslaan beschikbaar</span>';
        }
    } elseif ($field['type'] === 'users') {
        bkp_render_user_picker($name, $post ? bkp_get_linked_user_ids($id) : array(), 'bkp-user-picker--frontend');
    } elseif ($field['type'] === 'project') {
        bkp_render_project_select($name, absint($value), 'bkp-project-select--frontend');
    } elseif ($field['type'] === 'status') {
        bkp_render_status_select($name, $field['status_context'] ?? '', (string) $value);
    } elseif ($field['type'] === 'hidden_text') {
        echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'"><span class="bkp-muted-label">'.esc_html($value?:'—').'</span>';
    } elseif ($field['type'] === 'textarea') {
        echo '<textarea rows="2" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea>';
    } elseif ($field['type'] === 'checkbox') {
        echo '<label class="bkp-front-check"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value, '1', false).'><span></span></label>';
    } elseif ($field['type'] === 'months') {
        $selected = array_filter(explode(',', (string) $value));
        echo '<div class="bkp-front-months">';
        foreach (bkp_month_definitions() as $key=>$label) {
            echo '<label title="'.esc_attr($label).'"><input type="checkbox" name="rows['.esc_attr($index).'][months]['.esc_attr($key).']" value="1" '.checked(in_array($key, $selected, true), true, false).'><span>'.esc_html(substr($label, 0, 3)).'</span></label>';
        }
        echo '</div>';
    } elseif ($field['type'] === 'topic') {
        echo '<input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" list="bkp-front-todo-main-topics" placeholder="Kies of typ nieuw">';
    } else {
        $type = in_array($field['type'], array('date','email','url','tel','number'), true) ? $field['type'] : 'text';
        $placeholder = $field_key === 'responsible' ? 'Meerdere namen: scheiden met komma' : '';
        echo '<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"'.($placeholder!==''?' placeholder="'.esc_attr($placeholder).'"':'').'>';
    }
    echo '</td>';
}

function bkp_front_render_grid_row($entity, $config, $post, $index) {
    $id = $post ? $post->ID : 0;
    $search = $post ? $post->post_title . ' ' . bkp_project_name(bkp_get_project_id($id),'') . ' ' . get_post_meta($id, '_bkp_main_topic', true) . ' ' . get_post_meta($id, '_bkp_responsible', true) . ' ' . implode(' ', bkp_linked_user_names($id)) : '';
    $sort_order = $post ? get_post_meta($id, '_bkp_sort_order', true) : $index;
    echo '<tr class="bkp-front-grid-row" data-search="'.esc_attr(strtolower($search)).'">';
    if ($entity === 'project') {
        echo '<td class="bkp-front-order"><button type="button" class="bkp-front-move-up" aria-label="Omhoog">↑</button><button type="button" class="bkp-front-move-down" aria-label="Omlaag">↓</button><input class="bkp-front-sort-order" type="hidden" name="rows['.esc_attr($index).'][sort_order]" value="'.esc_attr($sort_order !== '' ? $sort_order : $index).'"></td>';
    }
    foreach ($config['fields'] as $key=>$field) bkp_front_render_field($key, $field, $index, $post);
    echo '<td class="bkp-front-delete"><label><input type="checkbox" class="bkp-front-delete-row" name="rows['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td>';
    echo '</tr>';
}

function bkp_front_render_grid_editor($entity) {
    $configs = bkp_admin_entity_configs();
    $config = $configs[$entity];
    $posts = bkp_front_posts_for_entity($entity);
    echo '<section class="bkp-editor-heading"><div><span class="bkp-eyebrow">Frontend beheer</span><h1>'.esc_html($config['title']).' wijzigen</h1><p>'.esc_html($config['intro']).'</p></div><a class="bkp-btn bkp-btn--light" href="'.esc_url(home_url('/#'.bkp_frontend_sections()[$entity]['tab'])).'">Bekijk onderdeel</a></section>';
    bkp_front_render_notice();
    echo '<form class="bkp-front-editor-form'.($entity==='project'?' bkp-front-project-form':'').'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="bkp_front_save"><input type="hidden" name="section" value="'.esc_attr($entity).'">';
    wp_nonce_field('bkp_front_save_' . $entity, 'bkp_front_nonce');
    echo '<div class="bkp-front-editor-toolbar"><input type="search" class="bkp-field bkp-front-grid-search" placeholder="Zoek in dit onderdeel"><button type="button" class="bkp-btn bkp-front-add-row">Nieuwe regel</button></div>';
    echo '<div class="bkp-front-grid-wrap"><table class="bkp-front-edit-grid"><thead><tr>';
    if ($entity === 'project') echo '<th>Volgorde</th>';
    foreach ($config['fields'] as $field) echo '<th>'.esc_html($field['label']).'</th>';
    echo '<th>Verwijderen</th></tr></thead><tbody class="bkp-front-grid-body">';
    $i = 0;
    foreach ($posts as $post) bkp_front_render_grid_row($entity, $config, $post, $i++);
    echo '</tbody></table></div><template class="bkp-front-row-template"><table><tbody>';
    bkp_front_render_grid_row($entity, $config, null, '__INDEX__');
    echo '</tbody></table></template><div class="bkp-front-save-bar"><span>Wijzigingen worden pas toegepast nadat je opslaat. Er wordt geen Exceldata geïmporteerd.</span><button class="bkp-btn" type="submit">Wijzigingen opslaan</button></div></form>';
    if($entity==='todos') {
        echo '<datalist id="bkp-front-todo-main-topics">';
        foreach(bkp_todo_main_topic_suggestions() as $topic) echo '<option value="'.esc_attr($topic).'"></option>';
        echo '</datalist>';
    }
}

function bkp_front_render_committees_editor() {
    $rows = bkp_committees();
    $names = array();
    foreach ($rows as $row) if ($row['committee'] !== '') $names[$row['committee']] = true;
    $committee_names = array_keys($names);
    natcasesort($committee_names);
    echo '<section class="bkp-editor-heading"><div><span class="bkp-eyebrow">Frontend beheer</span><h1>Commissies wijzigen</h1><p>Voeg snel namen toe of pas bestaande namen en commissies aan.</p></div><a class="bkp-btn bkp-btn--light" href="'.esc_url(home_url('/#committees')).'">Bekijk commissies</a></section>';
    bkp_front_render_notice();
    echo '<form class="bkp-front-editor-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="bkp_front_save"><input type="hidden" name="section" value="committees">';
    wp_nonce_field('bkp_front_save_committees', 'bkp_front_nonce');
    echo '<section class="bkp-front-quick-add"><h2>Persoon toevoegen</h2><div class="bkp-front-quick-grid"><label>Naam<input type="text" name="new_member_name"></label><label>Bestaande of nieuwe commissie<select name="new_member_committee" data-bkp-front-committee-choice><option value="">Kies commissie</option>';
    foreach ($committee_names as $name) echo '<option value="'.esc_attr($name).'">'.esc_html($name).'</option>';
    echo '<option value="__new__">+ Nieuwe commissie</option></select></label><label data-bkp-front-new-committee hidden>Naam nieuwe commissie<input type="text" name="new_committee_name"></label></div></section>';
    echo '<div class="bkp-front-grid-wrap"><table class="bkp-front-edit-grid"><thead><tr><th>Commissie</th><th>Naam</th><th>Verwijderen</th></tr></thead><tbody>';
    foreach ($rows as $index=>$row) {
        echo '<tr><td><input type="text" name="members['.esc_attr($index).'][committee]" value="'.esc_attr($row['committee']).'"></td><td><input type="text" name="members['.esc_attr($index).'][name]" value="'.esc_attr($row['name']).'"></td><td><label><input type="checkbox" class="bkp-front-delete-row" name="members['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td></tr>';
    }
    echo '</tbody></table></div><div class="bkp-front-save-bar"><span>Bestaande commissiegegevens blijven behouden totdat je ze hier wijzigt.</span><button class="bkp-btn" type="submit">Commissies opslaan</button></div></form>';
}

function bkp_front_render_documents_editor() {
    $documents = bkp_documents(false);
    echo '<section class="bkp-editor-heading"><div><span class="bkp-eyebrow">Frontend beheer</span><h1>Documenten wijzigen</h1><p>Upload een document, koppel het aan een project of wijzig wat voor bezoekers beschikbaar is.</p></div><a class="bkp-btn bkp-btn--light" href="'.esc_url(home_url('/#documents')).'">Bekijk documenten</a></section>';
    bkp_front_render_notice();
    echo '<form class="bkp-front-editor-form" method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="bkp_front_save"><input type="hidden" name="section" value="documents">';
    wp_nonce_field('bkp_front_save_documents', 'bkp_front_nonce');
    echo '<section class="bkp-front-quick-add"><h2>Nieuw document</h2><div class="bkp-front-quick-grid"><label>Bestand<input type="file" name="document_file"></label><label>Project';
    bkp_render_project_select('document_project_id',0);
    echo '</label><label>Titel<input type="text" name="document_title" placeholder="Leeg = bestandsnaam"></label><label>Beschrijving<textarea rows="2" name="document_description"></textarea></label><label class="bkp-front-inline-check"><input type="checkbox" name="document_visible" value="1" checked> Direct zichtbaar</label></div></section>';
    echo '<div class="bkp-front-grid-wrap"><table class="bkp-front-edit-grid"><thead><tr><th>Volgorde</th><th>Project</th><th>Titel</th><th>Bestand</th><th>Beschrijving</th><th>Zichtbaar</th><th>Verwijderen</th></tr></thead><tbody>';
    foreach ($documents as $document) {
        $id = $document['id'];
        echo '<tr><td><input type="hidden" name="documents['.esc_attr($id).'][id]" value="'.esc_attr($id).'"><input type="number" name="documents['.esc_attr($id).'][sort_order]" value="'.esc_attr($document['sort_order']).'"></td><td>';
        bkp_render_project_select('documents['.$id.'][project_id]',absint($document['project_id']??0));
        echo '</td><td><input type="text" name="documents['.esc_attr($id).'][title]" value="'.esc_attr($document['title']).'"></td><td><strong>'.esc_html($document['original_name']).'</strong><br><small>'.esc_html(size_format((int) $document['size'])).'</small></td><td><textarea rows="2" name="documents['.esc_attr($id).'][description]">'.esc_textarea($document['description']).'</textarea></td><td><input type="checkbox" name="documents['.esc_attr($id).'][visible]" value="1" '.checked($document['visible'], '1', false).'></td><td><label><input type="checkbox" class="bkp-front-delete-row" name="documents['.esc_attr($id).'][delete]" value="1"> verwijderen</label></td></tr>';
    }
    echo '</tbody></table></div><div class="bkp-front-save-bar"><span>Een verwijderd bestand wordt definitief uit de beveiligde documentenmap verwijderd.</span><button class="bkp-btn" type="submit">Documenten opslaan</button></div></form>';
}

function bkp_front_render_board_editor() {
    $board = bkp_normalise_board(get_option('bkp_board', array()));
    $contact = (array) get_option('bkp_contact_details', array());
    echo '<section class="bkp-editor-heading"><div><span class="bkp-eyebrow">Frontend beheer</span><h1>Bestuur en contact wijzigen</h1><p>Wijzig bestuursleden, telefoonnummers, e-mailadressen en algemene contactgegevens.</p></div><a class="bkp-btn bkp-btn--light" href="'.esc_url(home_url('/#info')).'">Bekijk info</a></section>';
    bkp_front_render_notice();
    echo '<form class="bkp-front-editor-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="bkp_front_save"><input type="hidden" name="section" value="board">';
    wp_nonce_field('bkp_front_save_board', 'bkp_front_nonce');
    echo '<div class="bkp-front-editor-toolbar"><span></span><button type="button" class="bkp-btn bkp-front-add-row">Nieuw bestuurslid</button></div><div class="bkp-front-grid-wrap"><table class="bkp-front-edit-grid"><thead><tr><th>Functie</th><th>Naam</th><th>Telefoon</th><th>E-mail</th><th>Notities</th><th>Verwijderen</th></tr></thead><tbody class="bkp-front-grid-body">';
    foreach ($board as $index=>$row) bkp_front_render_board_row($row, $index);
    echo '</tbody></table></div><template class="bkp-front-row-template"><table><tbody>';
    bkp_front_render_board_row(array(), '__INDEX__');
    echo '</tbody></table></template><section class="bkp-front-quick-add"><h2>Algemene contactgegevens</h2><div class="bkp-front-contact-grid"><label>Contactpersoon<input name="contact_name" value="'.esc_attr($contact['contact_name'] ?? '').'"></label><label>Telefoon<input type="tel" name="contact_phone" value="'.esc_attr($contact['phone'] ?? '').'"></label><label>E-mail<input type="email" name="contact_email" value="'.esc_attr($contact['email'] ?? '').'"></label><label>Website<input type="url" name="contact_website" value="'.esc_attr($contact['website'] ?? '').'"></label><label>Adres<textarea rows="2" name="contact_address">'.esc_textarea($contact['address'] ?? '').'</textarea></label><label>Toelichting<textarea rows="2" name="contact_notes">'.esc_textarea($contact['notes'] ?? get_option('bkp_contact', '')).'</textarea></label></div></section><div class="bkp-front-save-bar"><span>Deze gegevens worden direct in het tabblad Info gebruikt.</span><button class="bkp-btn" type="submit">Bestuur en contact opslaan</button></div></form>';
}

function bkp_front_render_board_row($row, $index) {
    echo '<tr class="bkp-front-grid-row"><td><input name="board['.esc_attr($index).'][role]" value="'.esc_attr($row['role'] ?? '').'"></td><td><input name="board['.esc_attr($index).'][name]" value="'.esc_attr($row['name'] ?? '').'"></td><td><input type="tel" name="board['.esc_attr($index).'][phone]" value="'.esc_attr($row['phone'] ?? '').'"></td><td><input type="email" name="board['.esc_attr($index).'][email]" value="'.esc_attr($row['email'] ?? '').'"></td><td><textarea rows="2" name="board['.esc_attr($index).'][notes]">'.esc_textarea($row['notes'] ?? '').'</textarea></td><td><label><input type="checkbox" class="bkp-front-delete-row" name="board['.esc_attr($index).'][delete]" value="1"> verwijderen</label></td></tr>';
}

function bkp_render_frontend_editor($section) {
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(bkp_frontend_editor_url($section)));
        exit;
    }
    $section = sanitize_key($section ?: 'dashboard');
    if ($section !== 'dashboard' && !bkp_user_can_frontend_edit($section)) {
        status_header(403);
        echo '<main class="bkp-main"><div class="bkp-wrap"><div class="bkp-empty"><p>Je hebt geen bewerkrechten voor dit onderdeel. Bekijken blijft wel mogelijk.</p><p><a class="bkp-btn" href="'.esc_url(home_url('/')).'">Terug naar de jaarplanning</a></p></div></div></main>';
        return;
    }
    echo '<main class="bkp-main bkp-editor-main"><div class="bkp-wrap">';
    bkp_front_editor_navigation($section);
    if ($section === 'dashboard') bkp_front_render_editor_dashboard();
    elseif (in_array($section, array('projects','events','project','inventories','todos','actions'), true)) bkp_front_render_grid_editor($section);
    elseif ($section === 'committees') bkp_front_render_committees_editor();
    elseif ($section === 'documents') bkp_front_render_documents_editor();
    elseif ($section === 'board') bkp_front_render_board_editor();
    echo '</div></main>';
}
