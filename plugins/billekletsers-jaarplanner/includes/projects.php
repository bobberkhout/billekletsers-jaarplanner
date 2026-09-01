<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Projecten vormen de centrale kapstok van de jaarplanner. Deze module maakt
 * geen projecten of koppelingen automatisch aan. Bestaande hoofdonderwerpen
 * kunnen uitsluitend via de expliciete koppelassistent worden omgezet.
 */

function bkp_projects($include_archived = true) {
    $posts = get_posts(array(
        'post_type'      => 'bkp_project',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    if ($include_archived) return $posts;
    $season = (string) get_option('bkp_season', '');
    return array_values(array_filter($posts, function($project) use ($season) {
        $project_season = trim((string) get_post_meta($project->ID, '_bkp_project_season', true));
        $status = strtolower(trim((string) get_post_meta($project->ID, '_bkp_project_status', true)));
        if (in_array($status, array('gearchiveerd','archief','geannuleerd'), true)) return false;
        return $project_season === '' || $season === '' || $project_season === $season;
    }));
}

function bkp_project_id_is_valid($project_id) {
    $project_id = absint($project_id);
    return $project_id && get_post_type($project_id) === 'bkp_project' && get_post_status($project_id) !== false;
}

function bkp_get_project_id($post_id) {
    $project_id = absint(get_post_meta($post_id, '_bkp_project_id', true));
    return bkp_project_id_is_valid($project_id) ? $project_id : 0;
}

function bkp_project_name($project_id, $fallback = 'Niet gekoppeld') {
    $project_id = absint($project_id);
    if (!$project_id) return $fallback;
    $project = get_post($project_id);
    return ($project && $project->post_type === 'bkp_project') ? $project->post_title : $fallback;
}

function bkp_project_options($include_empty = true) {
    $options = array();
    if ($include_empty) $options[0] = 'Niet gekoppeld';
    foreach (bkp_projects(true) as $project) $options[(int) $project->ID] = $project->post_title;
    return $options;
}

function bkp_render_project_select($name, $selected = 0, $class = '', $include_empty = true) {
    $selected = absint($selected);
    echo '<select name="'.esc_attr($name).'" class="'.esc_attr(trim('bkp-project-select '.$class)).'">';
    foreach (bkp_project_options($include_empty) as $id=>$label) {
        echo '<option value="'.esc_attr($id).'" '.selected($selected, (int) $id, false).'>'.esc_html($label).'</option>';
    }
    echo '</select>';
}

function bkp_project_statuses() {
    return bkp_status_options('projects');
}

function bkp_project_status($project_id) {
    $status = trim((string) get_post_meta($project_id, '_bkp_project_status', true));
    return $status !== '' ? $status : 'Voorbereiding';
}

function bkp_project_is_complete_status($status) {
    return in_array(strtolower(trim((string) $status)), array('afgerond','gereed','klaar','geannuleerd','gearchiveerd'), true);
}

function bkp_project_linked_documents($project_id, $visible_only = true) {
    $project_id = absint($project_id);
    return array_values(array_filter(bkp_documents($visible_only), function($document) use ($project_id) {
        return absint($document['project_id'] ?? 0) === $project_id;
    }));
}

function bkp_project_items($project_id) {
    $project_id = absint($project_id);
    $items = array('events'=>array(),'project'=>array(),'inventories'=>array(),'todos'=>array(),'actions'=>array(),'documents'=>array());
    if (!$project_id) return $items;
    $map = array(
        'events'      => bkp_events(),
        'project'     => bkp_tasks('Projectplanning'),
        'inventories' => bkp_inventories(),
        'todos'       => bkp_tasks('To-do'),
        'actions'     => bkp_actions(),
    );
    foreach ($map as $section=>$posts) {
        foreach ($posts as $post) if (bkp_get_project_id($post->ID) === $project_id) $items[$section][] = $post;
    }
    $items['documents'] = bkp_project_linked_documents($project_id, true);
    return $items;
}

function bkp_project_progress($project_id) {
    $items = bkp_project_items($project_id);
    $total = 0;
    $done = 0;
    $overdue = 0;
    $today = wp_date('Y-m-d');

    foreach ($items['project'] as $post) {
        $total++;
        if (bkp_is_done(get_post_meta($post->ID, '_bkp_task_status', true))) $done++;
    }
    foreach ($items['todos'] as $post) {
        $total++;
        $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
        $deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
        if (bkp_is_done($status)) $done++;
        elseif ($deadline && $deadline < $today) $overdue++;
    }
    foreach ($items['inventories'] as $post) {
        $total++;
        $status = (string) get_post_meta($post->ID, '_bkp_task_status', true);
        $deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
        if (bkp_is_done($status) || (!$deadline && !bkp_inventory_is_open($post))) $done++;
        elseif ($deadline && $deadline < $today && bkp_inventory_is_open($post)) $overdue++;
    }
    foreach ($items['actions'] as $post) {
        $total++;
        $status = (string) get_post_meta($post->ID, '_bkp_done', true);
        $deadline = (string) get_post_meta($post->ID, '_bkp_due_date', true);
        if (bkp_is_done($status)) $done++;
        elseif ($deadline && $deadline < $today) $overdue++;
    }

    $percent = $total ? (int) round(($done / $total) * 100) : 0;
    $health = $overdue > 0 ? 'kritiek' : (($total - $done) > 0 ? 'op schema' : 'gereed');
    return array(
        'items'       => $items,
        'total'       => $total,
        'done'        => $done,
        'open'        => max(0, $total - $done),
        'overdue'     => $overdue,
        'percent'     => $percent,
        'health'      => $health,
    );
}

function bkp_project_members($project_id) {
    return bkp_get_linked_user_ids($project_id);
}

function bkp_project_migration_candidates() {
    $candidates = array();
    foreach (bkp_tasks('To-do') as $todo) {
        if (bkp_get_project_id($todo->ID)) continue;
        $topic = trim((string) get_post_meta($todo->ID, '_bkp_main_topic', true));
        if ($topic === '') continue;
        $normalised = function_exists('mb_strtolower') ? mb_strtolower($topic, 'UTF-8') : strtolower($topic);
        $key = 'topic_' . substr(md5($normalised), 0, 16);
        if (!isset($candidates[$key])) $candidates[$key] = array('name'=>$topic,'items'=>array());
        $candidates[$key]['items'][] = $todo;
    }
    uasort($candidates, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $candidates;
}

function bkp_find_project_by_title($title) {
    $needle = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $title), 'UTF-8') : strtolower(trim((string) $title));
    if ($needle === '') return 0;
    foreach (bkp_projects(true) as $project) {
        $candidate = function_exists('mb_strtolower') ? mb_strtolower(trim($project->post_title), 'UTF-8') : strtolower(trim($project->post_title));
        if ($candidate === $needle) return (int) $project->ID;
    }
    return 0;
}

function bkp_project_link_count($project_id) {
    $count = 0;
    foreach (array('bkp_event','bkp_task','bkp_inventory','bkp_action') as $post_type) {
        $query = new WP_Query(array(
            'post_type'=>$post_type,
            'post_status'=>'any',
            'posts_per_page'=>1,
            'fields'=>'ids',
            'meta_query'=>array(array('key'=>'_bkp_project_id','value'=>absint($project_id),'compare'=>'=')),
        ));
        $count += (int) $query->found_posts;
    }
    $count += count(bkp_project_linked_documents($project_id, false));
    return $count;
}
