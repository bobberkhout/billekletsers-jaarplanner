<?php
if (!defined('ABSPATH')) { exit; }

function bkp_responsibility_section_for_post($post) {
    if (!$post instanceof WP_Post) return '';
    if ($post->post_type === 'bkp_project') return 'projects';
    if ($post->post_type === 'bkp_event') return 'events';
    if ($post->post_type === 'bkp_inventory') return 'inventories';
    if ($post->post_type === 'bkp_action') return 'actions';
    if ($post->post_type === 'bkp_task') {
        $category = (string) get_post_meta($post->ID, '_bkp_category', true);
        if ($category === 'Projectplanning') return 'project';
        if ($category === 'To-do') return 'todos';
    }
    return '';
}

function bkp_responsibility_source_deadline($post, $section) {
    if (!$post instanceof WP_Post) return '';
    if ($section === 'projects') $date = (string) get_post_meta($post->ID, '_bkp_project_end', true);
    elseif ($section === 'events') $date = (string) get_post_meta($post->ID, '_bkp_date', true);
    elseif (in_array($section, array('project','todos','actions','inventories'), true)) $date = (string) get_post_meta($post->ID, '_bkp_due_date', true);
    else $date = '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
}

function bkp_responsibility_deadline($post, $section) {
    $source = bkp_responsibility_source_deadline($post, $section);
    if ($source !== '') return $source;
    // Backwards compatibility for projectplanning deadlines that were previously
    // stored only by the reminders plugin.
    $custom = (string) get_post_meta($post->ID, '_bkpr_deadline', true);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom) ? $custom : '';
}

function bkp_responsibility_status($post, $section) {
    if ($section === 'projects') return bkp_project_status($post->ID);
    if ($section === 'events') return get_post_meta($post->ID, '_bkp_confirmed', true) === '1' ? 'Vastgesteld' : 'Concept';
    if ($section === 'actions') return (string) get_post_meta($post->ID, '_bkp_done', true);
    if ($section === 'inventories') return bkp_inventory_status_label($post);
    return (string) get_post_meta($post->ID, '_bkp_task_status', true);
}

function bkp_responsibility_is_complete($post, $section, $deadline, $status) {
    if ($section === 'projects') return bkp_project_is_complete_status($status);
    if ($section === 'events') {
        return $deadline !== '' && $deadline < wp_date('Y-m-d');
    }
    if ($section === 'inventories') return !bkp_inventory_is_open($post);
    return bkp_is_done($status);
}

function bkp_user_responsibilities($user_id=0, $include_completed=true) {
    $user_id = $user_id ? absint($user_id) : get_current_user_id();
    if (!$user_id) return array();

    $posts = get_posts(array(
        'post_type' => array('bkp_project','bkp_event','bkp_task','bkp_inventory','bkp_action'),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ));

    $sections = bkp_frontend_sections();
    $today = wp_date('Y-m-d');
    $today_ts = strtotime($today . ' 00:00:00');
    $items = array();

    foreach ($posts as $post) {
        if (!in_array($user_id, bkp_get_linked_user_ids($post->ID), true)) continue;
        $section = bkp_responsibility_section_for_post($post);
        if ($section === '' || empty($sections[$section])) continue;

        $deadline = bkp_responsibility_deadline($post, $section);
        $status = trim(bkp_responsibility_status($post, $section));
        $complete = bkp_responsibility_is_complete($post, $section, $deadline, $status);
        if ($complete && !$include_completed) continue;

        $days = null;
        if ($deadline !== '') {
            $deadline_ts = strtotime($deadline . ' 00:00:00');
            if ($deadline_ts !== false && $today_ts !== false) $days = (int) floor(($deadline_ts - $today_ts) / DAY_IN_SECONDS);
        }

        if ($complete) $bucket = 'completed';
        elseif ($days !== null && $days < 0) $bucket = 'overdue';
        elseif ($days !== null && $days <= 14) $bucket = 'soon';
        elseif ($days !== null) $bucket = 'later';
        else $bucket = 'undated';

        $tab = $sections[$section]['tab'];
        $responsible = trim((string) get_post_meta($post->ID, '_bkp_responsible', true));
        $topic = $section === 'todos' ? trim((string) get_post_meta($post->ID, '_bkp_main_topic', true)) : '';
        $project_id = $section === 'projects' ? (int) $post->ID : bkp_get_project_id($post->ID);
        $project_name = bkp_project_name($project_id, $topic !== '' ? $topic : 'Niet gekoppeld');
        $external_url = '';

        $items[] = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'section' => $section,
            'section_label' => $sections[$section]['label'],
            'tab' => $tab,
            'deadline' => $deadline,
            'days' => $days,
            'status' => $status !== '' ? $status : 'Open',
            'complete' => $complete,
            'bucket' => $bucket,
            'responsible' => $responsible,
            'topic' => $topic,
            'project_id' => $project_id,
            'project_name' => $project_name,
            'view_url' => function_exists('bkp_responsibility_detail_url') ? bkp_responsibility_detail_url($post->ID) : home_url('/#responsibilities'),
            'section_url' => home_url('/#' . $tab),
            'edit_url' => bkp_user_can_frontend_edit($section, $user_id) ? bkp_frontend_editor_url($section) : '',
            'external_url' => $external_url,
        );
    }

    $bucket_order = array('overdue'=>0,'soon'=>1,'later'=>2,'undated'=>3,'completed'=>4);
    usort($items, function($a, $b) use ($bucket_order) {
        $bucket_compare = ($bucket_order[$a['bucket']] ?? 99) <=> ($bucket_order[$b['bucket']] ?? 99);
        if ($bucket_compare !== 0) return $bucket_compare;
        $a_date = $a['deadline'] ?: '9999-12-31';
        $b_date = $b['deadline'] ?: '9999-12-31';
        if ($a_date !== $b_date) return strcmp($a_date, $b_date);
        $project_compare = strcasecmp((string)($a['project_name']??''),(string)($b['project_name']??''));
        if ($project_compare !== 0) return $project_compare;
        return strcasecmp($a['title'], $b['title']);
    });

    return $items;
}

function bkp_responsibility_groups($user_id=0) {
    $groups = array('overdue'=>array(),'soon'=>array(),'later'=>array(),'undated'=>array(),'completed'=>array());
    foreach (bkp_user_responsibilities($user_id, true) as $item) $groups[$item['bucket']][] = $item;
    return $groups;
}

function bkp_responsibility_when_label($item) {
    if (empty($item['deadline'])) return 'Geen deadline';
    $days = $item['days'];
    if ($days === 0) return 'Vandaag';
    if ($days === 1) return 'Morgen';
    if ($days === -1) return 'Gisteren verlopen';
    if ($days !== null && $days < 0) return abs($days) . ' dagen te laat';
    if ($days !== null) return 'Over ' . $days . ' dagen';
    return bkp_format_date($item['deadline']);
}
