<?php
if (!defined('ABSPATH')) { exit; }

function bkp_render_header_actions() {
    $documents_url = home_url('/#documents');
    $download_url = add_query_arg('bkp_full_planning', '1', home_url('/'));

    echo '<div class="bkp-header-actions">';
    echo '<a class="bkp-btn bkp-btn--light bkp-header-my-tasks" data-open-tab="responsibilities" href="'.esc_url(home_url('/#responsibilities')).'">Mijn verantwoordelijkheden</a>';
    if (is_user_logged_in()) {
        echo '<details class="bkp-action-menu"><summary class="bkp-btn">Account</summary><div class="bkp-action-menu-panel">';
        if (bkp_user_frontend_sections()) echo '<a href="'.esc_url(bkp_frontend_editor_url('dashboard')).'">Planning wijzigen</a>';
        echo '<a data-open-tab="documents" href="'.esc_url($documents_url).'">Documenten</a>';
        echo '<a href="'.esc_url($download_url).'">Volledige planning downloaden</a>';
        if (current_user_can('edit_posts')) echo '<a href="'.esc_url(admin_url('admin.php?page=bkp-planning')).'">Beheer openen</a>';
        do_action('bkp_account_menu_items');
        echo '<a href="'.esc_url(wp_logout_url(home_url('/'))).'">Uitloggen</a>';
        echo '</div></details>';
    } else {
        echo '<details class="bkp-action-menu"><summary class="bkp-btn">Opties</summary><div class="bkp-action-menu-panel">';
        echo '<a href="'.esc_url(wp_login_url(home_url('/'))).'">Inloggen met persoonlijk account</a>';
        echo '<a data-open-tab="documents" href="'.esc_url($documents_url).'">Documenten</a>';
        echo '<a href="'.esc_url($download_url).'">Volledige planning downloaden</a>';
        echo '<a href="'.esc_url(add_query_arg('planning_logout','1',home_url('/'))).'">Planning afsluiten</a>';
        echo '</div></details>';
    }
    echo '</div>';
}

function bkp_render_planner_app() {
    $template = BKP_CORE_DIR . 'templates/app.php';
    if (is_readable($template)) include $template;
}

function bkp_core_footer_label() {
    return 'jaarplanner-plugin ' . BKP_CORE_VERSION;
}

function bkp_render_responsibility_group($title, $items, $modifier='') {
    if (!$items) return;
    $by_project=array();
    foreach($items as $item) {
        $project=trim((string)($item['project_name']??''));
        if($project==='') $project='Niet gekoppeld';
        if(!isset($by_project[$project])) $by_project[$project]=array();
        $by_project[$project][]=$item;
    }
    uksort($by_project,'strnatcasecmp');
    echo '<section class="bkp-responsibility-group '.esc_attr($modifier).'" data-bkp-responsibility-group>';
    echo '<div class="bkp-responsibility-group-head"><h3>'.esc_html($title).'</h3><span data-bkp-responsibility-group-count>'.esc_html(count($items)).'</span></div>';
    foreach($by_project as $project_name=>$project_items) {
        echo '<div class="bkp-responsibility-project" data-bkp-responsibility-project-group><h4 class="bkp-responsibility-project-title">'.esc_html($project_name).'<span data-bkp-responsibility-project-count>'.esc_html(count($project_items)).'</span></h4><div class="bkp-responsibility-list">';
        foreach ($project_items as $item) {
            $search_source = implode(' ', array_filter(array($item['title']??'', $item['project_name']??'', $item['section_label']??'', $item['status']??'', $item['responsible']??'')));
            echo '<article class="bkp-responsibility-item is-clickable" tabindex="0" role="link" data-bkp-responsibility-item data-project="'.esc_attr((string)($item['project_id']??0)).'" data-section="'.esc_attr((string)($item['section']??'')).'" data-bucket="'.esc_attr((string)($item['bucket']??'')).'" data-search="'.esc_attr($search_source).'" data-bkp-responsibility-url="'.esc_url($item['view_url']).'" aria-label="Open '.esc_attr($item['title']).'">';
            echo '<div class="bkp-responsibility-main"><div class="bkp-responsibility-kicker">'.esc_html($item['section_label']).'</div><h4>'.esc_html($item['title']).'</h4><div class="bkp-responsibility-meta">';
            echo '<span>'.esc_html($item['deadline'] ? bkp_format_date($item['deadline']) : 'Geen deadline').'</span>';
            echo '<span class="bkp-responsibility-when">'.esc_html(bkp_responsibility_when_label($item)).'</span>';
            echo '<span>Status: '.esc_html($item['status']).'</span>';
            if ($item['responsible'] !== '') echo '<span>Samen met: '.esc_html($item['responsible']).'</span>';
            echo '</div></div><div class="bkp-responsibility-actions">';
            echo '<a class="bkp-btn" href="'.esc_url($item['view_url']).'">Openen</a>';
            if ($item['edit_url'] !== '') echo '<a class="bkp-btn bkp-btn--light" href="'.esc_url($item['edit_url']).'">Volledig beheer</a>';
            echo '</div></article>';
        }
        echo '</div></div>';
    }
    echo '</section>';
}
