<?php
if (!defined('ABSPATH')) { exit; }

/**
 * A focused detail screen for a single item from "Mijn verantwoordelijkheden".
 * Linked users may update the limited operational fields of their own item,
 * without receiving broad edit rights for an entire planning section.
 */
function bkp_responsibility_detail_url($post_id, $args=array()) {
    $query = array_merge(array('bkp_responsibility_item'=>absint($post_id)), (array) $args);
    return add_query_arg($query, home_url('/'));
}

function bkp_responsibility_detail_post($post_id) {
    $post = get_post(absint($post_id));
    if (!$post instanceof WP_Post || $post->post_status !== 'publish') return null;
    return bkp_responsibility_section_for_post($post) !== '' ? $post : null;
}

function bkp_user_can_view_responsibility_item($post_id, $user_id=0) {
    $user_id = $user_id ? absint($user_id) : get_current_user_id();
    if (!$user_id) return false;
    $post = bkp_responsibility_detail_post($post_id);
    if (!$post) return false;
    if (user_can($user_id, 'manage_options')) return true;
    if (in_array($user_id, bkp_get_linked_user_ids($post->ID), true)) return true;
    $section = bkp_responsibility_section_for_post($post);
    return $section !== '' && bkp_user_can_frontend_edit($section, $user_id);
}

function bkp_user_can_update_responsibility_item($post_id, $user_id=0) {
    return bkp_user_can_view_responsibility_item($post_id, $user_id);
}

function bkp_responsibility_operational_fields($post, $section) {
    $fields = array();
    if ($section === 'projects') {
        $fields['deadline'] = array('label'=>'Einddatum','meta'=>'_bkp_project_end','type'=>'date');
        $fields['status'] = array('label'=>'Status','meta'=>'_bkp_project_status','type'=>'status','status_context'=>'projects');
    } elseif ($section === 'events') {
        $fields['deadline'] = array('label'=>'Datum','meta'=>'_bkp_date','type'=>'date');
        $fields['time'] = array('label'=>'Tijd','meta'=>'_bkp_time','type'=>'text');
        $fields['location'] = array('label'=>'Locatie','meta'=>'_bkp_location','type'=>'text');
        $fields['confirmed'] = array('label'=>'Vastgesteld','meta'=>'_bkp_confirmed','type'=>'checkbox');
    } elseif ($section === 'project') {
        $fields['deadline'] = array('label'=>'Deadline','meta'=>'_bkp_due_date','type'=>'date');
        $fields['status'] = array('label'=>'Status','meta'=>'_bkp_task_status','type'=>'status','status_context'=>'project');
    } elseif ($section === 'inventories') {
        $fields['deadline'] = array('label'=>'Sluitdatum','meta'=>'_bkp_due_date','type'=>'date');
        $fields['status'] = array('label'=>'Interne status','meta'=>'_bkp_task_status','type'=>'status','status_context'=>'inventories');
    } elseif ($section === 'todos') {
        $fields['deadline'] = array('label'=>'Deadline','meta'=>'_bkp_due_date','type'=>'date');
        $fields['status'] = array('label'=>'Status','meta'=>'_bkp_task_status','type'=>'status','status_context'=>'todos');
    } elseif ($section === 'actions') {
        $fields['deadline'] = array('label'=>'Einddatum','meta'=>'_bkp_due_date','type'=>'date');
        $fields['status'] = array('label'=>'Status / afhandeling','meta'=>'_bkp_done','type'=>'status','status_context'=>'actions');
    }
    return $fields;
}

function bkp_responsibility_progress_log($post_id) {
    $stored = get_post_meta(absint($post_id), '_bkp_progress_log', true);
    if (!is_array($stored)) return array();
    $out = array();
    foreach ($stored as $entry) {
        if (!is_array($entry)) continue;
        $text = sanitize_textarea_field((string)($entry['text'] ?? ''));
        if ($text === '') continue;
        $out[] = array(
            'id'=>sanitize_key((string)($entry['id'] ?? '')),
            'user_id'=>absint($entry['user_id'] ?? 0),
            'author'=>sanitize_text_field((string)($entry['author'] ?? 'Onbekend')),
            'created_at'=>sanitize_text_field((string)($entry['created_at'] ?? '')),
            'text'=>$text,
            'system'=>!empty($entry['system']),
        );
    }
    usort($out, function($a,$b){ return strcmp((string)$b['created_at'], (string)$a['created_at']); });
    return $out;
}

function bkp_add_responsibility_progress_entry($post_id, $text, $system=false, $user_id=0) {
    $text = sanitize_textarea_field((string)$text);
    if ($text === '') return false;
    $user_id = $user_id ? absint($user_id) : get_current_user_id();
    $user = $user_id ? get_userdata($user_id) : null;
    $entries = bkp_responsibility_progress_log($post_id);
    array_unshift($entries, array(
        'id'=>str_replace('-', '', wp_generate_uuid4()),
        'user_id'=>$user_id,
        'author'=>$user ? $user->display_name : 'Systeem',
        'created_at'=>current_time('mysql'),
        'text'=>$text,
        'system'=>(bool)$system,
    ));
    $entries = array_slice($entries, 0, 250);
    update_post_meta(absint($post_id), '_bkp_progress_log', $entries);
    return true;
}

function bkp_responsibility_field_value_label($field, $value) {
    if (($field['type'] ?? '') === 'checkbox') return (string)$value === '1' ? 'Ja' : 'Nee';
    if (($field['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) return bkp_format_date($value);
    return trim((string)$value) !== '' ? (string)$value : 'leeg';
}

function bkp_handle_responsibility_detail_save() {
    if (!is_user_logged_in()) wp_die('Log eerst in.', 'Geen toegang', array('response'=>403));
    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id || !bkp_user_can_update_responsibility_item($post_id)) {
        wp_die('Je mag dit onderdeel niet wijzigen.', 'Geen toegang', array('response'=>403));
    }
    check_admin_referer('bkp_save_responsibility_' . $post_id, 'bkp_responsibility_nonce');

    $post = bkp_responsibility_detail_post($post_id);
    if (!$post) wp_die('Dit onderdeel bestaat niet meer.', 'Niet gevonden', array('response'=>404));
    $section = bkp_responsibility_section_for_post($post);
    $fields = bkp_responsibility_operational_fields($post, $section);
    $changes = array();

    foreach ($fields as $key=>$field) {
        $meta = $field['meta'];
        $old = (string)get_post_meta($post_id, $meta, true);
        if (($field['type'] ?? '') === 'checkbox') {
            $new = !empty($_POST[$key]) ? '1' : '0';
        } elseif (($field['type'] ?? '') === 'date') {
            $raw = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
            $new = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
        } elseif (($field['type'] ?? '') === 'status') {
            $new = bkp_sanitise_status($field['status_context'] ?? '', wp_unslash($_POST[$key] ?? ''), $old);
        } else {
            $new = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        }
        if ($new !== $old) {
            if ($new === '') delete_post_meta($post_id, $meta);
            else update_post_meta($post_id, $meta, $new);
            $changes[] = $field['label'] . ': ' . bkp_responsibility_field_value_label($field, $old) . ' → ' . bkp_responsibility_field_value_label($field, $new);
        }
    }

    $note = sanitize_textarea_field(wp_unslash($_POST['progress_note'] ?? ''));
    if ($note !== '') bkp_add_responsibility_progress_entry($post_id, $note, false);
    if ($changes) bkp_add_responsibility_progress_entry($post_id, implode("\n", $changes), true);

    update_post_meta($post_id, '_bkp_last_responsibility_editor', get_current_user_id());
    update_post_meta($post_id, '_bkp_last_responsibility_edit_at', current_time('mysql'));

    $message = array();
    if ($changes) $message[] = count($changes) . ' gegeven(s) aangepast';
    if ($note !== '') $message[] = 'notitie toegevoegd';
    if (!$message) $message[] = 'geen wijzigingen gevonden';

    wp_safe_redirect(bkp_responsibility_detail_url($post_id, array(
        'bkp_saved'=>'1',
        'bkp_message'=>implode(' en ', $message) . '.',
    )));
    exit;
}
add_action('admin_post_bkp_save_responsibility_detail', 'bkp_handle_responsibility_detail_save');

function bkp_render_responsibility_detail($post_id) {
    if (!is_user_logged_in()) {
        echo '<div class="bkp-wrap"><section class="bkp-card bkp-responsibility-detail-error"><h1>Persoonlijk inloggen</h1><p>Log in met je persoonlijke account om dit onderdeel te openen en notulen toe te voegen.</p><a class="bkp-btn" href="'.esc_url(wp_login_url(bkp_responsibility_detail_url($post_id))).'">Inloggen</a></section></div>';
        return;
    }
    $post = bkp_responsibility_detail_post($post_id);
    if (!$post || !bkp_user_can_view_responsibility_item($post_id)) {
        status_header(403);
        echo '<div class="bkp-wrap"><section class="bkp-card bkp-responsibility-detail-error"><h1>Geen toegang</h1><p>Dit onderdeel is niet aan jouw account gekoppeld.</p><a class="bkp-btn" href="'.esc_url(home_url('/#responsibilities')).'">Terug naar mijn verantwoordelijkheden</a></section></div>';
        return;
    }

    $section = bkp_responsibility_section_for_post($post);
    $sections = bkp_frontend_sections();
    $section_label = $sections[$section]['label'] ?? 'Onderdeel';
    $tab = $sections[$section]['tab'] ?? 'responsibilities';
    $fields = bkp_responsibility_operational_fields($post, $section);
    $project_name = $section === 'projects' ? $post->post_title : bkp_project_name(bkp_get_project_id($post->ID), 'Niet gekoppeld');
    $responsible = trim((string)get_post_meta($post->ID, '_bkp_responsible', true));
    $linked_names = bkp_linked_user_names($post->ID);
    $general_notes = trim((string)get_post_meta($post->ID, '_bkp_notes', true));
    $log = bkp_responsibility_progress_log($post->ID);
    $reminder_override = trim((string)get_post_meta($post->ID, '_bkpr_deadline', true));
    $source_deadline = bkp_responsibility_source_deadline($post, $section);
    ?>
    <section class="bkp-hero bkp-detail-hero"><div class="bkp-wrap">
        <div class="bkp-eyebrow"><?php echo esc_html($section_label); ?> · <?php echo esc_html($project_name); ?></div>
        <h1><?php echo esc_html($post->post_title); ?></h1>
        <p>Werk de actuele gegevens bij en leg voortgang of notulen vast. De wijzigingen zijn direct zichtbaar voor de andere betrokkenen.</p>
    </div></section>
    <main class="bkp-main"><div class="bkp-wrap bkp-responsibility-detail-wrap">
        <div class="bkp-detail-toolbar">
            <a class="bkp-btn bkp-btn--light" href="<?php echo esc_url(home_url('/#responsibilities')); ?>">← Mijn verantwoordelijkheden</a>
            <a class="bkp-btn bkp-btn--light" href="<?php echo esc_url(home_url('/#'.$tab)); ?>">Bekijk <?php echo esc_html(strtolower($section_label)); ?></a>
        </div>
        <?php if (!empty($_GET['bkp_saved'])): ?>
            <div class="bkp-front-notice" role="status"><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['bkp_message'] ?? 'Wijzigingen opgeslagen.'))); ?></div>
        <?php endif; ?>

        <div class="bkp-detail-grid">
            <section class="bkp-card bkp-responsibility-detail-card">
                <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Actuele gegevens</span><h2>Onderdeel bijwerken</h2></div></div>
                <dl class="bkp-detail-summary">
                    <div><dt>Project</dt><dd><?php echo esc_html($project_name); ?></dd></div>
                    <div><dt>Onderdeel</dt><dd><?php echo esc_html($section_label); ?></dd></div>
                    <div><dt>Verantwoordelijke tekst</dt><dd><?php echo esc_html($responsible !== '' ? $responsible : 'Niet ingevuld'); ?></dd></div>
                    <div><dt>Gekoppelde gebruikers</dt><dd><?php echo esc_html($linked_names ? implode(', ', $linked_names) : 'Geen gebruikers gekoppeld'); ?></dd></div>
                </dl>

                <form class="bkp-responsibility-detail-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bkp_save_responsibility_detail">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>">
                    <?php wp_nonce_field('bkp_save_responsibility_' . $post->ID, 'bkp_responsibility_nonce'); ?>
                    <div class="bkp-detail-fields">
                    <?php foreach ($fields as $key=>$field):
                        $value = (string)get_post_meta($post->ID, $field['meta'], true);
                    ?>
                        <?php if ($field['type'] === 'checkbox'): ?>
                            <label class="bkp-detail-checkbox"><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($value, '1'); ?>><span><?php echo esc_html($field['label']); ?></span></label>
                        <?php elseif ($field['type'] === 'status'): ?>
                            <label><span><?php echo esc_html($field['label']); ?></span><?php bkp_render_status_select($key, $field['status_context'] ?? '', $value, 'bkp-field'); ?></label>
                        <?php else: ?>
                            <label><span><?php echo esc_html($field['label']); ?></span><input class="bkp-field" type="<?php echo esc_attr($field['type'] === 'date' ? 'date' : 'text'); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>"></label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                    <?php if ($reminder_override !== '' && $reminder_override !== $source_deadline): ?>
                        <div class="bkp-detail-warning"><strong>Let op:</strong> voor herinneringen is apart <?php echo esc_html(bkp_format_date($reminder_override)); ?> ingesteld. Die datum blijft leidend voor e-mail en push totdat de herinneringsinstelling wordt aangepast.</div>
                    <?php endif; ?>
                    <label class="bkp-detail-note"><span>Nieuwe voortgangsnotitie / notulen</span><textarea class="bkp-field" name="progress_note" rows="6" maxlength="5000" placeholder="Wat is besproken, besloten of uitgevoerd?"></textarea><small>Iedere notitie krijgt automatisch jouw naam, datum en tijd.</small></label>
                    <div class="bkp-detail-save"><button class="bkp-btn" type="submit">Wijzigingen opslaan</button><span>Je wijzigt alleen dit onderdeel, niet de volledige planning.</span></div>
                </form>
            </section>

            <aside class="bkp-card bkp-progress-log-card">
                <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Historie</span><h2>Voortgang en notulen</h2></div><span class="bkp-log-count"><?php echo esc_html(count($log)); ?></span></div>
                <?php if ($general_notes !== ''): ?><div class="bkp-general-notes"><strong>Algemene notities</strong><p><?php echo nl2br(esc_html($general_notes)); ?></p></div><?php endif; ?>
                <?php if (!$log): ?><div class="bkp-empty">Er zijn nog geen voortgangsnotities toegevoegd.</div>
                <?php else: ?><ol class="bkp-progress-log">
                    <?php foreach ($log as $entry):
                        $timestamp = strtotime((string)$entry['created_at']);
                        $when = $timestamp ? wp_date('d-m-Y H:i', $timestamp) : (string)$entry['created_at'];
                    ?>
                    <li class="<?php echo !empty($entry['system']) ? 'is-system' : ''; ?>">
                        <div class="bkp-progress-log-head"><strong><?php echo esc_html($entry['author']); ?></strong><time><?php echo esc_html($when); ?></time></div>
                        <p><?php echo nl2br(esc_html($entry['text'])); ?></p>
                    </li>
                    <?php endforeach; ?>
                </ol><?php endif; ?>
            </aside>
        </div>
    </div></main>
    <?php
}
