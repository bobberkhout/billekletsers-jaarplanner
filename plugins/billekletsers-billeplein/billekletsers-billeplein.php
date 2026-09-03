<?php
/**
 * Plugin Name: Billekletsers Billeplein
 * Plugin URI: https://www.cvdebillekletsers.nl/
 * Description: Interne sociale plek voor werkende leden: vragen, ideeën, hulpvragen, polls, reacties en e-mailmeldingen.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: C.V. De Billekletsers
 * Text Domain: billekletsers-billeplein
 */
if (!defined('ABSPATH')) exit;

define('BKB_VERSION','1.1.0');
define('BKB_FILE',__FILE__);
define('BKB_DIR',plugin_dir_path(__FILE__));
define('BKB_URL',plugin_dir_url(__FILE__));

add_action('init','bkb_register_post_type');
add_action('wp_enqueue_scripts','bkb_enqueue_frontend');
add_action('admin_menu','bkb_admin_settings_menu');
add_action('add_meta_boxes','bkb_add_meta_boxes');
add_action('save_post_bkb_post','bkb_save_admin_meta',10,2);
add_action('bkp_personal_nav_items','bkb_render_nav_item');
add_action('bkp_overview_extensions','bkb_render_overview_block');
add_action('bkp_extra_panels','bkb_render_panel');
add_action('bkp_account_menu_items','bkb_render_account_link');

foreach (array('create','reply','react','vote','status','preferences') as $action) {
    add_action('admin_post_bkb_'.$action,'bkb_handle_'.$action);
}

register_activation_hook(__FILE__, function(){
    if (get_option('bkb_email_new_enabled',null) === null) update_option('bkb_email_new_enabled','1',false);
    if (get_option('bkb_email_reply_enabled',null) === null) update_option('bkb_email_reply_enabled','1',false);
});

function bkb_register_post_type(){
    register_post_type('bkb_post',array(
        'labels'=>array(
            'name'=>'Billeplein','singular_name'=>'Billeplein-bericht','add_new_item'=>'Nieuw Billeplein-bericht','edit_item'=>'Billeplein-bericht bewerken','menu_name'=>'Billeplein'
        ),
        'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'menu_icon'=>'dashicons-format-chat','supports'=>array('title','editor','author','comments'),
        'capability_type'=>'post','map_meta_cap'=>true,'rewrite'=>false,'query_var'=>false
    ));
}

function bkb_types(){
    return array('question'=>'Vraag','idea'=>'Idee','help'=>'Hulp gezocht','poll'=>'Poll');
}
function bkb_type_label($type){ $types=bkb_types(); return $types[$type]??'Bericht'; }
function bkb_status_label($post_id){
    $type=get_post_meta($post_id,'_bkb_type',true);
    $status=get_post_meta($post_id,'_bkb_status',true) ?: 'open';
    if ($type==='question') return $status==='answered'?'Beantwoord':'Open vraag';
    if ($type==='help') return $status==='done'?'Afgerond':'Hulp gezocht';
    if ($type==='poll' && bkb_poll_closed($post_id)) return 'Poll gesloten';
    return $status==='closed'?'Gesloten':'Open';
}
function bkb_is_personal_member(){ return is_user_logged_in(); }
function bkb_projects(){
    if (function_exists('bkp_projects')) return bkp_projects(false);
    return get_posts(array('post_type'=>'bkp_project','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC'));
}
function bkb_front_url($post_id=0,$notice=''){
    $args=array();
    if ($post_id) $args['bkb_post']=absint($post_id);
    if ($notice) $args['bkb_notice']=sanitize_key($notice);
    $url=$args?add_query_arg($args,home_url('/')):home_url('/');
    return $url.'#billeplein';
}
function bkb_require_member(){
    if (!is_user_logged_in()) wp_die('Log in met je persoonlijke account om het Billeplein te gebruiken.',403);
}
function bkb_verify_post($post_id){
    $post=get_post($post_id);
    if (!$post || $post->post_type!=='bkb_post' || $post->post_status!=='publish') wp_die('Billeplein-bericht niet gevonden.',404);
    return $post;
}
function bkb_enqueue_frontend(){
    if (!is_user_logged_in()) return;
    wp_enqueue_style('bkb-billeplein',BKB_URL.'assets/billeplein.css',array(),BKB_VERSION);
    wp_enqueue_script('bkb-billeplein',BKB_URL.'assets/billeplein.js',array(),BKB_VERSION,true);
}

function bkb_render_nav_item(){
    if (!bkb_is_personal_member()) return;
    echo '<button class="bkp-tab bkp-tab--primary" type="button" aria-selected="false" data-tab="billeplein">Billeplein</button>';
}
function bkb_render_account_link(){
    if (!bkb_is_personal_member()) return;
    echo '<a data-open-tab="billeplein" href="#billeplein">Billeplein</a>';
}

function bkb_recent_posts($limit=20){
    return get_posts(array('post_type'=>'bkb_post','post_status'=>'publish','numberposts'=>$limit,'orderby'=>'date','order'=>'DESC'));
}
function bkb_count_open($type){
    $meta=array(array('key'=>'_bkb_type','value'=>$type));
    if ($type==='question') $meta[]=array('key'=>'_bkb_status','value'=>'answered','compare'=>'!=');
    if ($type==='help') $meta[]=array('key'=>'_bkb_status','value'=>'done','compare'=>'!=');
    $q=new WP_Query(array('post_type'=>'bkb_post','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>$meta,'no_found_rows'=>true));
    if ($type!=='poll') return count($q->posts);
    $count=0; foreach($q->posts as $id) if(!bkb_poll_closed($id)) $count++; return $count;
}

function bkb_render_overview_block(){
    if (!bkb_is_personal_member()) return;
    $recent=bkb_recent_posts(3);
    $questions=bkb_count_open('question');
    $polls=bkb_count_open('poll');
    ?>
    <section class="bkp-card bkb-overview-card">
      <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Samen binnen de vereniging</span><h3>Billeplein</h3><p class="bkb-overview-intro">Stel een vraag, deel een idee, zoek hulp of laat leden stemmen.</p></div><a data-open-tab="billeplein" href="#billeplein">Open Billeplein</a></div>
      <div class="bkb-overview-actions">
        <button type="button" data-open-tab="billeplein" data-bkb-compose="question">Stel een vraag</button>
        <button type="button" data-open-tab="billeplein" data-bkb-compose="idea">Deel een idee</button>
        <button type="button" data-open-tab="billeplein" data-bkb-compose="help">Hulp gezocht</button>
        <button type="button" data-open-tab="billeplein" data-bkb-compose="poll">Maak een poll</button>
      </div>
      <div class="bkb-overview-meta"><span><strong><?php echo esc_html($questions); ?></strong> open vragen</span><span><strong><?php echo esc_html($polls); ?></strong> open polls</span></div>
      <?php if($recent): ?><div class="bkb-mini-list"><?php foreach($recent as $post): ?>
        <a data-open-tab="billeplein" href="#billeplein"><span><?php echo esc_html(bkb_type_label(get_post_meta($post->ID,'_bkb_type',true))); ?></span><strong><?php echo esc_html($post->post_title); ?></strong><small><?php echo esc_html(get_the_author_meta('display_name',$post->post_author).' · '.wp_date('j M',strtotime($post->post_date))); ?></small></a>
      <?php endforeach; ?></div><?php endif; ?>
    </section>
    <?php
}

function bkb_notice(){
    $notice=isset($_GET['bkb_notice'])?sanitize_key(wp_unslash($_GET['bkb_notice'])):'';
    $map=array('created'=>'Je bericht staat op het Billeplein.','replied'=>'Je reactie is geplaatst.','voted'=>'Je stem is opgeslagen.','reacted'=>'Reactie bijgewerkt.','status'=>'Status bijgewerkt.','preferences'=>'E-mailvoorkeuren opgeslagen.','poll-options'=>'Een poll heeft minimaal twee antwoordopties nodig.');
    if ($notice && isset($map[$notice])) echo '<div class="bkb-notice">'.esc_html($map[$notice]).'</div>';
}
function bkb_render_panel(){
    if (!bkb_is_personal_member()) return;
    $posts=bkb_recent_posts(50); $projects=bkb_projects(); $uid=get_current_user_id();
    ?>
    <section class="bkp-panel" data-panel="billeplein" id="billeplein">
      <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Voor werkende leden</span><h2 class="bkp-section-title">Billeplein</h2><p class="bkp-section-intro">Een plek voor vragen, ideeën, hulpvragen, polls en reacties binnen de vereniging.</p></div></div>
      <?php bkb_notice(); ?>
      <div class="bkb-top-grid">
        <details class="bkp-card bkb-compose" id="bkb-compose"><summary>Nieuw op het Billeplein</summary>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bkb_create"><?php wp_nonce_field('bkb_create','bkb_nonce'); ?>
            <div class="bkb-form-grid">
              <label><span>Soort bericht</span><select class="bkp-field" name="bkb_type" id="bkb-type"><?php foreach(bkb_types() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
              <label><span>Project</span><select class="bkp-field" name="bkb_project"><option value="0">Algemeen / geen project</option><?php foreach($projects as $project): ?><option value="<?php echo esc_attr($project->ID); ?>"><?php echo esc_html($project->post_title); ?></option><?php endforeach; ?></select></label>
            </div>
            <label><span>Titel</span><input class="bkp-field" type="text" name="bkb_title" maxlength="160" required></label>
            <label><span>Bericht</span><textarea class="bkp-field" name="bkb_body" rows="5" required></textarea></label>
            <div class="bkb-poll-fields" id="bkb-poll-fields" hidden>
              <span class="bkb-poll-options-label">Antwoordopties <small>(minstens 2)</small></span>
              <div class="bkb-poll-options-list" id="bkb-poll-options-list">
                <div class="bkb-poll-option-row"><input class="bkp-field" type="text" name="bkb_poll_option[]" placeholder="Keuze 1" maxlength="200"><button type="button" class="bkb-poll-option-remove" aria-label="Verwijder deze keuze" hidden>&times;</button></div>
                <div class="bkb-poll-option-row"><input class="bkp-field" type="text" name="bkb_poll_option[]" placeholder="Keuze 2" maxlength="200"><button type="button" class="bkb-poll-option-remove" aria-label="Verwijder deze keuze" hidden>&times;</button></div>
              </div>
              <button type="button" class="bkp-btn bkp-btn--light bkb-poll-option-add" id="bkb-poll-option-add">+ Extra keuze toevoegen</button>
              <div class="bkb-form-grid"><label class="bkb-check"><input type="checkbox" name="bkb_poll_multiple" value="1"> Meerdere antwoorden toestaan</label><label><span>Sluitdatum (optioneel)</span><input class="bkp-field" type="date" name="bkb_poll_close"></label></div>
            </div>
            <button class="bkp-btn" type="submit">Plaatsen op Billeplein</button>
          </form>
        </details>
        <section class="bkp-card bkb-email-settings"><h3>E-mailmeldingen</h3><p>Kies welke Billeplein-berichten je per e-mail wilt ontvangen.</p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="bkb_preferences"><?php wp_nonce_field('bkb_preferences','bkb_nonce'); ?>
            <label class="bkb-check"><input type="checkbox" name="bkb_notify_new" value="1" <?php checked(get_user_meta($uid,'bkb_notify_new',true)!=='0'); ?>> E-mail bij nieuw bericht of nieuwe poll</label>
            <label class="bkb-check"><input type="checkbox" name="bkb_notify_replies" value="1" <?php checked(get_user_meta($uid,'bkb_notify_replies',true)!=='0'); ?>> E-mail bij een reactie op mijn bericht</label>
            <button class="bkp-btn bkp-btn--light" type="submit">Voorkeuren opslaan</button>
          </form>
        </section>
      </div>
      <div class="bkb-filters" aria-label="Billeplein filters">
        <label><span>Soort</span><select class="bkp-field" id="bkb-filter-type"><option value="">Alles</option><?php foreach(bkb_types() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
        <label><span>Project</span><select class="bkp-field" id="bkb-filter-project"><option value="">Alle projecten</option><option value="0">Algemeen</option><?php foreach($projects as $project): ?><option value="<?php echo esc_attr($project->ID); ?>"><?php echo esc_html($project->post_title); ?></option><?php endforeach; ?></select></label>
        <label class="bkb-filter-search"><span>Zoeken</span><input class="bkp-field" id="bkb-filter-search" type="search" placeholder="Zoek op titel of tekst"></label>
      </div>
      <div class="bkb-feed" id="bkb-feed">
        <?php if(!$posts): ?><div class="bkp-empty">Het Billeplein is nog leeg. Plaats de eerste vraag, het eerste idee of de eerste poll.</div><?php else: foreach($posts as $post) bkb_render_post_card($post); endif; ?>
      </div>
      <div class="bkp-empty" id="bkb-no-results" hidden>Geen Billeplein-berichten gevonden met deze filters.</div>
    </section>
    <?php
}

function bkb_render_post_card($post){
    $type=get_post_meta($post->ID,'_bkb_type',true) ?: 'idea';
    $project_id=absint(get_post_meta($post->ID,'_bkb_project',true));
    $project_name=$project_id?get_the_title($project_id):'Algemeen';
    $author=get_the_author_meta('display_name',$post->post_author);
    $search=strtolower(wp_strip_all_tags($post->post_title.' '.$post->post_content.' '.$project_name));
    $comments=get_comments(array('post_id'=>$post->ID,'status'=>'approve','order'=>'ASC'));
    $likes=(array)get_post_meta($post->ID,'_bkb_like_users',true); $helps=(array)get_post_meta($post->ID,'_bkb_help_users',true);
    $uid=get_current_user_id();
    ?>
    <article class="bkp-card bkb-post" id="bkb-post-<?php echo esc_attr($post->ID); ?>" data-bkb-post data-type="<?php echo esc_attr($type); ?>" data-project="<?php echo esc_attr($project_id); ?>" data-search="<?php echo esc_attr($search); ?>">
      <header class="bkb-post-head"><div><div class="bkb-post-labels"><span class="bkb-type is-<?php echo esc_attr($type); ?>"><?php echo esc_html(bkb_type_label($type)); ?></span><span><?php echo esc_html($project_name); ?></span><span class="bkb-status"><?php echo esc_html(bkb_status_label($post->ID)); ?></span></div><h3><?php echo esc_html($post->post_title); ?></h3><small><?php echo esc_html($author.' · '.wp_date('j F Y H:i',strtotime($post->post_date))); ?></small></div></header>
      <div class="bkb-post-body"><?php echo wpautop(esc_html($post->post_content)); ?></div>
      <?php if($type==='poll') bkb_render_poll($post->ID); ?>
      <div class="bkb-post-actions">
        <?php bkb_reaction_form($post->ID,'like','👍',$likes,in_array($uid,array_map('intval',$likes),true)); ?>
        <?php if($type==='help') bkb_reaction_form($post->ID,'help','🙋 Ik help mee',$helps,in_array($uid,array_map('intval',$helps),true)); ?>
        <span><?php echo esc_html(count($comments)); ?> reactie<?php echo count($comments)===1?'':'s'; ?></span>
        <?php if((int)$post->post_author===$uid || current_user_can('edit_others_posts')) bkb_status_form($post->ID,$type); ?>
      </div>
      <details class="bkb-comments" <?php echo isset($_GET['bkb_post'])&&absint($_GET['bkb_post'])===$post->ID?'open':''; ?>><summary>Reacties bekijken / reageren</summary>
        <?php if($comments): ?><div class="bkb-comment-list"><?php foreach($comments as $comment): ?><div class="bkb-comment"><strong><?php echo esc_html($comment->comment_author); ?></strong><small><?php echo esc_html(wp_date('j M H:i',strtotime($comment->comment_date))); ?></small><p><?php echo nl2br(esc_html($comment->comment_content)); ?></p></div><?php endforeach; ?></div><?php endif; ?>
        <form class="bkb-reply-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="bkb_reply"><input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>"><?php wp_nonce_field('bkb_reply_'.$post->ID,'bkb_nonce'); ?><label><span>Jouw reactie</span><textarea class="bkp-field" name="comment" rows="3" required></textarea></label><button class="bkp-btn" type="submit">Reageren</button></form>
      </details>
    </article>
    <?php
}
function bkb_reaction_form($post_id,$reaction,$label,$users,$active){
    ?><form class="bkb-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="bkb_react"><input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>"><input type="hidden" name="reaction" value="<?php echo esc_attr($reaction); ?>"><?php wp_nonce_field('bkb_react_'.$post_id,'bkb_nonce'); ?><button class="bkb-reaction <?php echo $active?'is-active':''; ?>" type="submit"><?php echo esc_html($label); ?> <b><?php echo esc_html(count($users)); ?></b></button></form><?php
}
function bkb_status_form($post_id,$type){
    $status=get_post_meta($post_id,'_bkb_status',true) ?: 'open';
    if($type==='question'){ $next=$status==='answered'?'open':'answered'; $label=$status==='answered'?'Heropen vraag':'Markeer beantwoord'; }
    elseif($type==='help'){ $next=$status==='done'?'open':'done'; $label=$status==='done'?'Heropen hulpvraag':'Markeer afgerond'; }
    else return;
    ?><form class="bkb-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="bkb_status"><input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>"><input type="hidden" name="status" value="<?php echo esc_attr($next); ?>"><?php wp_nonce_field('bkb_status_'.$post_id,'bkb_nonce'); ?><button class="bkb-text-action" type="submit"><?php echo esc_html($label); ?></button></form><?php
}
function bkb_poll_closed($post_id){
    $close=(string)get_post_meta($post_id,'_bkb_poll_close',true);
    return $close!=='' && $close < wp_date('Y-m-d');
}
function bkb_render_poll($post_id){
    $options=(array)get_post_meta($post_id,'_bkb_poll_options',true); if(count($options)<2) return;
    $votes=(array)get_post_meta($post_id,'_bkb_poll_votes',true); $uid=get_current_user_id();
    $my=array_map('intval',(array)($votes[$uid]??array())); $multiple=get_post_meta($post_id,'_bkb_poll_multiple',true)==='1'; $closed=bkb_poll_closed($post_id);
    $counts=array_fill(0,count($options),0); foreach($votes as $choice_list) foreach((array)$choice_list as $idx) if(isset($counts[(int)$idx])) $counts[(int)$idx]++;
    $voters=count($votes);
    echo '<div class="bkb-poll"><div class="bkb-poll-head"><strong>'.($closed?'Uitslag':'Breng je stem uit').'</strong><span>'.$voters.' stemmer'.($voters===1?'':'s').'</span></div>';
    if(!$closed){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bkb_vote"><input type="hidden" name="post_id" value="'.esc_attr($post_id).'">'; wp_nonce_field('bkb_vote_'.$post_id,'bkb_nonce'); echo '<div class="bkb-poll-options">'; foreach($options as $i=>$option){ $input=$multiple?'checkbox':'radio'; echo '<label><input type="'.$input.'" name="choices[]" value="'.esc_attr($i).'" '.checked(in_array($i,$my,true),true,false).'> <span>'.esc_html($option).'</span></label>'; } echo '</div><button class="bkp-btn bkp-btn--light" type="submit">'.($my?'Stem wijzigen':'Stemmen').'</button></form>'; }
    if($my || $closed){ echo '<div class="bkb-poll-results">'; $denom=max(1,array_sum($counts)); foreach($options as $i=>$option){ $pct=round(($counts[$i]/$denom)*100); echo '<div><span><b>'.esc_html($option).'</b><em>'.esc_html($counts[$i].' · '.$pct.'%').'</em></span><i><u style="width:'.esc_attr($pct).'%"></u></i></div>'; } echo '</div>'; }
    $close=(string)get_post_meta($post_id,'_bkb_poll_close',true); if($close) echo '<small>Sluitdatum: '.esc_html(wp_date('j F Y',strtotime($close))).'</small>';
    echo '</div>';
}

function bkb_handle_create(){
    bkb_require_member(); check_admin_referer('bkb_create','bkb_nonce');
    $type=sanitize_key($_POST['bkb_type']??'idea'); if(!isset(bkb_types()[$type])) $type='idea';
    $title=sanitize_text_field(wp_unslash($_POST['bkb_title']??'')); $body=sanitize_textarea_field(wp_unslash($_POST['bkb_body']??''));
    if($title===''||$body==='') wp_die('Titel en bericht zijn verplicht.');
    $options=array(); if($type==='poll'){ $raw_options=isset($_POST['bkb_poll_option'])?(array)wp_unslash($_POST['bkb_poll_option']):array(); foreach($raw_options as $line){$line=sanitize_text_field($line);if($line!=='')$options[]=$line;} $options=array_values(array_unique($options)); if(count($options)<2){wp_safe_redirect(bkb_front_url(0,'poll-options'));exit;} }
    $id=wp_insert_post(array('post_type'=>'bkb_post','post_status'=>'publish','post_title'=>$title,'post_content'=>$body,'post_author'=>get_current_user_id(),'comment_status'=>'open'),true);
    if(is_wp_error($id)) wp_die(esc_html($id->get_error_message()));
    update_post_meta($id,'_bkb_type',$type); update_post_meta($id,'_bkb_status','open');
    $project=absint($_POST['bkb_project']??0); if($project && get_post_type($project)==='bkp_project') update_post_meta($id,'_bkb_project',$project);
    if($type==='poll'){ update_post_meta($id,'_bkb_poll_options',$options); update_post_meta($id,'_bkb_poll_multiple',isset($_POST['bkb_poll_multiple'])?'1':'0'); $close=sanitize_text_field($_POST['bkb_poll_close']??''); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$close)) update_post_meta($id,'_bkb_poll_close',$close); }
    bkb_mail_new_post($id); wp_safe_redirect(bkb_front_url($id,'created')); exit;
}
function bkb_handle_reply(){
    bkb_require_member(); $post_id=absint($_POST['post_id']??0); $post=bkb_verify_post($post_id); check_admin_referer('bkb_reply_'.$post_id,'bkb_nonce');
    $content=sanitize_textarea_field(wp_unslash($_POST['comment']??'')); if($content==='') wp_die('Reactie is leeg.');
    $user=wp_get_current_user(); $cid=wp_new_comment(array('comment_post_ID'=>$post_id,'comment_content'=>$content,'user_id'=>$user->ID,'comment_author'=>$user->display_name,'comment_author_email'=>$user->user_email,'comment_approved'=>1),true);
    if(is_wp_error($cid)) wp_die(esc_html($cid->get_error_message())); bkb_mail_reply($post_id,$user->ID,$content); wp_safe_redirect(bkb_front_url($post_id,'replied')); exit;
}
function bkb_handle_react(){
    bkb_require_member(); $post_id=absint($_POST['post_id']??0); bkb_verify_post($post_id); check_admin_referer('bkb_react_'.$post_id,'bkb_nonce');
    $reaction=sanitize_key($_POST['reaction']??'like'); if(!in_array($reaction,array('like','help'),true)) wp_die('Onbekende reactie.');
    $key=$reaction==='help'?'_bkb_help_users':'_bkb_like_users'; $users=array_map('intval',(array)get_post_meta($post_id,$key,true)); $uid=get_current_user_id();
    if(in_array($uid,$users,true)) $users=array_values(array_diff($users,array($uid))); else $users[]=$uid; update_post_meta($post_id,$key,array_values(array_unique($users)));
    wp_safe_redirect(bkb_front_url($post_id,'reacted')); exit;
}
function bkb_handle_vote(){
    bkb_require_member(); $post_id=absint($_POST['post_id']??0); bkb_verify_post($post_id); check_admin_referer('bkb_vote_'.$post_id,'bkb_nonce'); if(bkb_poll_closed($post_id)) wp_die('Deze poll is gesloten.');
    $options=(array)get_post_meta($post_id,'_bkb_poll_options',true); $choices=array_values(array_unique(array_map('absint',(array)($_POST['choices']??array())))); $choices=array_values(array_filter($choices,fn($i)=>isset($options[$i]))); if(!$choices) wp_die('Kies minimaal één antwoord.');
    if(get_post_meta($post_id,'_bkb_poll_multiple',true)!=='1') $choices=array_slice($choices,0,1); $votes=(array)get_post_meta($post_id,'_bkb_poll_votes',true); $votes[get_current_user_id()]=$choices; update_post_meta($post_id,'_bkb_poll_votes',$votes);
    wp_safe_redirect(bkb_front_url($post_id,'voted')); exit;
}
function bkb_handle_status(){
    bkb_require_member(); $post_id=absint($_POST['post_id']??0); $post=bkb_verify_post($post_id); check_admin_referer('bkb_status_'.$post_id,'bkb_nonce'); if((int)$post->post_author!==get_current_user_id()&&!current_user_can('edit_others_posts')) wp_die('Geen toestemming.',403);
    $status=sanitize_key($_POST['status']??'open'); if(!in_array($status,array('open','answered','done','closed'),true)) $status='open'; update_post_meta($post_id,'_bkb_status',$status); wp_safe_redirect(bkb_front_url($post_id,'status')); exit;
}
function bkb_handle_preferences(){
    bkb_require_member(); check_admin_referer('bkb_preferences','bkb_nonce'); $uid=get_current_user_id(); update_user_meta($uid,'bkb_notify_new',isset($_POST['bkb_notify_new'])?'1':'0'); update_user_meta($uid,'bkb_notify_replies',isset($_POST['bkb_notify_replies'])?'1':'0'); wp_safe_redirect(bkb_front_url(0,'preferences')); exit;
}

function bkb_mail_new_post($post_id){
    if(get_option('bkb_email_new_enabled','1')!=='1') return; $post=get_post($post_id); if(!$post) return; $author=absint($post->post_author); $type=bkb_type_label(get_post_meta($post_id,'_bkb_type',true));
    $subject='[Billeplein] Nieuwe '.strtolower($type).': '.$post->post_title; $login=wp_login_url(bkb_front_url($post_id));
    $body="Er is een nieuw bericht op het Billeplein.\n\n$type: {$post->post_title}\n\nLog in en reageer:\n$login\n\nJe kunt deze meldingen uitzetten bij E-mailmeldingen op het Billeplein.";
    foreach(get_users(array('fields'=>array('ID','user_email'))) as $user){ if((int)$user->ID===$author||!is_email($user->user_email)) continue; if(get_user_meta($user->ID,'bkb_notify_new',true)==='0') continue; wp_mail($user->user_email,$subject,$body); }
}
function bkb_mail_reply($post_id,$reply_user_id,$comment){
    if(get_option('bkb_email_reply_enabled','1')!=='1') return; $post=get_post($post_id); if(!$post||absint($post->post_author)===$reply_user_id) return; $owner=get_userdata($post->post_author); if(!$owner||!is_email($owner->user_email)||get_user_meta($owner->ID,'bkb_notify_replies',true)==='0') return; $replier=get_userdata($reply_user_id); $name=$replier?$replier->display_name:'Een lid'; $login=wp_login_url(bkb_front_url($post_id)); $excerpt=wp_trim_words($comment,24,'…');
    wp_mail($owner->user_email,'[Billeplein] Nieuwe reactie op: '.$post->post_title,"$name reageerde op jouw Billeplein-bericht:\n\n$excerpt\n\nLog in en reageer:\n$login");
}

function bkb_add_meta_boxes(){ add_meta_box('bkb_details','Billeplein details','bkb_admin_meta_box','bkb_post','side','default'); }
function bkb_admin_meta_box($post){
    wp_nonce_field('bkb_admin_meta','bkb_admin_nonce'); $type=get_post_meta($post->ID,'_bkb_type',true)?:'idea'; $project=absint(get_post_meta($post->ID,'_bkb_project',true)); $status=get_post_meta($post->ID,'_bkb_status',true)?:'open';
    echo '<p><label><strong>Soort</strong><br><select name="bkb_admin_type" style="width:100%">'; foreach(bkb_types() as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($type,$key,false).'>'.esc_html($label).'</option>'; echo '</select></label></p>';
    echo '<p><label><strong>Project</strong><br><select name="bkb_admin_project" style="width:100%"><option value="0">Algemeen</option>'; foreach(bkb_projects() as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($project,$p->ID,false).'>'.esc_html($p->post_title).'</option>'; echo '</select></label></p>';
    echo '<p><label><strong>Status</strong><br><select name="bkb_admin_status" style="width:100%">'; foreach(array('open'=>'Open','answered'=>'Beantwoord','done'=>'Afgerond','closed'=>'Gesloten') as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>'; echo '</select></label></p>';
}
function bkb_save_admin_meta($post_id,$post){
    if(!isset($_POST['bkb_admin_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkb_admin_nonce'])),'bkb_admin_meta')||defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE||!current_user_can('edit_post',$post_id)) return;
    $type=sanitize_key($_POST['bkb_admin_type']??'idea'); if(isset(bkb_types()[$type])) update_post_meta($post_id,'_bkb_type',$type); $project=absint($_POST['bkb_admin_project']??0); update_post_meta($post_id,'_bkb_project',$project); $status=sanitize_key($_POST['bkb_admin_status']??'open'); update_post_meta($post_id,'_bkb_status',$status);
}
function bkb_admin_settings_menu(){ add_submenu_page('edit.php?post_type=bkb_post','Billeplein instellingen','Instellingen','manage_options','bkb-settings','bkb_admin_settings_page'); }
function bkb_admin_settings_page(){
    if(!current_user_can('manage_options')) return; if(isset($_POST['bkb_settings_nonce'])&&wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bkb_settings_nonce'])),'bkb_settings')){ update_option('bkb_email_new_enabled',isset($_POST['email_new'])?'1':'0',false); update_option('bkb_email_reply_enabled',isset($_POST['email_reply'])?'1':'0',false); echo '<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>'; }
    echo '<div class="wrap"><h1>Billeplein instellingen</h1><form method="post">'; wp_nonce_field('bkb_settings','bkb_settings_nonce'); echo '<p><label><input type="checkbox" name="email_new" value="1" '.checked(get_option('bkb_email_new_enabled','1'),'1',false).'> E-mailmeldingen bij nieuwe berichten en polls toestaan</label></p><p><label><input type="checkbox" name="email_reply" value="1" '.checked(get_option('bkb_email_reply_enabled','1'),'1',false).'> E-mailmeldingen bij reacties toestaan</label></p><p class="description">Leden kunnen hun eigen meldingen op het Billeplein uitschakelen.</p><p><button class="button button-primary" type="submit">Opslaan</button></p></form></div>';
}
