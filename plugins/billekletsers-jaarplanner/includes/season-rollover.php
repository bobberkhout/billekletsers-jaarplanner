<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Functional season rollover. Nothing runs automatically: dates are only
 * changed after an administrator creates, reviews and explicitly applies a
 * proposal.
 */

function bkp_rollover_parse_season($season) {
    if (preg_match('/(\d{4})\D+(\d{4})/', (string) $season, $m)) {
        return array('start'=>(int)$m[1], 'end'=>(int)$m[2]);
    }
    $year=(int)wp_date('Y');
    return array('start'=>$year, 'end'=>$year+1);
}

function bkp_rollover_easter_sunday($year) {
    $year=(int)$year;
    $a=$year%19;
    $b=intdiv($year,100);
    $c=$year%100;
    $d=intdiv($b,4);
    $e=$b%4;
    $f=intdiv($b+8,25);
    $g=intdiv($b-$f+1,3);
    $h=(19*$a+$b-$d-$g+15)%30;
    $i=intdiv($c,4);
    $k=$c%4;
    $l=(32+2*$e+2*$i-$h-$k)%7;
    $m=intdiv($a+11*$h+22*$l,451);
    $month=intdiv($h+$l-7*$m+114,31);
    $day=(($h+$l-7*$m+114)%31)+1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$month,$day),wp_timezone());
}

function bkp_rollover_carnival_dates($year) {
    $easter=bkp_rollover_easter_sunday((int)$year);
    return array(
        'easter'=>$easter,
        'saturday'=>$easter->modify('-50 days'),
        'sunday'=>$easter->modify('-49 days'),
        'monday'=>$easter->modify('-48 days'),
        'tuesday'=>$easter->modify('-47 days'),
        'ash_wednesday'=>$easter->modify('-46 days'),
    );
}

function bkp_rollover_date($value) {
    $value=trim((string)$value);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)) return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
    $errors=DateTimeImmutable::getLastErrors();
    if($date===false || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) return null;
    return $date;
}

function bkp_rollover_month_name($number) {
    $names=array(1=>'januari',2=>'februari',3=>'maart',4=>'april',5=>'mei',6=>'juni',7=>'juli',8=>'augustus',9=>'september',10=>'oktober',11=>'november',12=>'december');
    return $names[(int)$number]??'';
}

function bkp_rollover_dutch_date($date,$with_year=true) {
    if(!$date instanceof DateTimeInterface) return '';
    $text=$date->format('j').' '.bkp_rollover_month_name((int)$date->format('n'));
    if($with_year) $text.=' '.$date->format('Y');
    return $text;
}

function bkp_rollover_period_label($dates) {
    $from=$dates['saturday']; $to=$dates['tuesday'];
    if($from->format('Y-m')===$to->format('Y-m')) return $from->format('j').' t/m '.bkp_rollover_dutch_date($to,true);
    if($from->format('Y')===$to->format('Y')) return bkp_rollover_dutch_date($from,false).' t/m '.bkp_rollover_dutch_date($to,true);
    return bkp_rollover_dutch_date($from,true).' t/m '.bkp_rollover_dutch_date($to,true);
}

function bkp_rollover_add_year($date,$years=1) {
    if(!$date instanceof DateTimeImmutable) return null;
    $target_year=(int)$date->format('Y')+(int)$years;
    $month=(int)$date->format('m');
    $day=(int)$date->format('d');
    while($day>28 && !checkdate($month,$day,$target_year)) $day--;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d',$target_year,$month,$day),wp_timezone());
}

function bkp_rollover_proposed_date($value,$old_carnival_sunday,$new_carnival_sunday) {
    $date=bkp_rollover_date($value);
    $old=bkp_rollover_date($old_carnival_sunday);
    $new=bkp_rollover_date($new_carnival_sunday);
    if(!$date || !$old || !$new) return array('date'=>$value,'reason'=>'Niet automatisch te bepalen');

    $offset=(int)$old->diff($date)->format('%r%a');
    if($offset>=-70 && $offset<=21) {
        $proposed=$new->modify(($offset>=0?'+':'').$offset.' days');
        return array('date'=>$proposed->format('Y-m-d'),'reason'=>'Zelfde afstand tot carnavalszondag ('.($offset>=0?'+':'').$offset.' dagen)');
    }

    $proposed=bkp_rollover_add_year($date,1);
    return array('date'=>$proposed?$proposed->format('Y-m-d'):$value,'reason'=>'Zelfde kalenderdatum, één jaar later');
}

function bkp_rollover_default_context() {
    $current=bkp_rollover_parse_season(get_option('bkp_season',''));
    $next=array('start'=>$current['start']+1,'end'=>$current['end']+1);
    $old=bkp_rollover_carnival_dates($current['end']);
    $new=bkp_rollover_carnival_dates($next['end']);
    $stored_old=(string)get_option('bkp_carnival_sunday','');
    if(!bkp_rollover_date($stored_old)) $stored_old=$old['sunday']->format('Y-m-d');
    return array(
        'current_season'=>$current['start'].'-'.$current['end'],
        'target_season'=>$next['start'].'-'.$next['end'],
        'old_carnival_sunday'=>$stored_old,
        'new_carnival_sunday'=>$new['sunday']->format('Y-m-d'),
        'carnival_period'=>bkp_rollover_period_label($new),
        'carnival_year'=>$next['end'],
    );
}

function bkp_rollover_source_check($year,$dates) {
    $url='https://www.optochtenkalender.nl/carnaval/'.absint($year).'.html';
    $result=array('url'=>$url,'status'=>'not_checked','message'=>'Online bron niet gecontroleerd.');
    $response=wp_safe_remote_get($url,array('timeout'=>6,'redirection'=>3,'user-agent'=>'WordPress/'.get_bloginfo('version').' '.home_url('/')));
    if(is_wp_error($response)) {
        $result['status']='unavailable';
        $result['message']='De online bron kon nu niet worden bereikt. De datums zijn wel kalenderkundig berekend.';
        return $result;
    }
    $code=(int)wp_remote_retrieve_response_code($response);
    $body=wp_strip_all_tags((string)wp_remote_retrieve_body($response));
    if($code<200 || $code>=300 || $body==='') {
        $result['status']='unavailable';
        $result['message']='De online bron gaf geen bruikbaar antwoord. De datums zijn wel kalenderkundig berekend.';
        return $result;
    }
    $needles=array(
        bkp_rollover_dutch_date($dates['saturday'],true),
        bkp_rollover_dutch_date($dates['sunday'],true),
        bkp_rollover_dutch_date($dates['tuesday'],true),
    );
    $matches=0;
    foreach($needles as $needle) if(stripos($body,$needle)!==false) $matches++;
    if($matches>=2) {
        $result['status']='confirmed';
        $result['message']='De berekende carnavalsdata komen overeen met de online bron.';
    } else {
        $result['status']='different';
        $result['message']='De online bron kon niet eenduidig met het voorstel worden vergeleken. Controleer de bronlink voor akkoord.';
    }
    return $result;
}

function bkp_rollover_row_key($post_id,$meta_key) {
    return substr(hash_hmac('sha256',absint($post_id).'|'.sanitize_key($meta_key),wp_salt('nonce')),0,24);
}

function bkp_rollover_add_preview_row(&$rows,$post,$meta_key,$section,$field,$old_carnival,$new_carnival) {
    $old=(string)get_post_meta($post->ID,$meta_key,true);
    if($old==='' || !bkp_rollover_date($old)) return;
    $proposal=bkp_rollover_proposed_date($old,$old_carnival,$new_carnival);
    $rows[]=array(
        'key'=>bkp_rollover_row_key($post->ID,$meta_key),
        'post_id'=>(int)$post->ID,
        'post_type'=>(string)$post->post_type,
        'meta_key'=>$meta_key,
        'section'=>$section,
        'title'=>(string)$post->post_title,
        'field'=>$field,
        'old'=>$old,
        'new'=>$proposal['date'],
        'reason'=>$proposal['reason'],
    );
}

function bkp_rollover_current_month_rows() {
    $stored=get_option('bkp_months',array());
    $rows=array();
    if(is_array($stored)) {
        foreach($stored as $row) {
            $key=sanitize_text_field((string)($row['key']??''));
            if(!preg_match('/^(\d{4})-(\d{2})$/',$key,$m)) continue;
            $rows[]=array('key'=>$key,'label'=>sanitize_text_field((string)($row['label']??bkp_rollover_month_name((int)$m[2]))),'year'=>absint($row['year']??$m[1]));
        }
    }
    if($rows) return $rows;
    foreach(bkp_month_definitions() as $key=>$label) {
        if(!preg_match('/^(\d{4})-(\d{2})$/',$key,$m)) continue;
        $rows[]=array('key'=>$key,'label'=>bkp_rollover_month_name((int)$m[2]),'year'=>(int)$m[1]);
    }
    return $rows;
}

function bkp_rollover_shift_month_key($key,$years=1) {
    if(!preg_match('/^(\d{4})-(\d{2})$/',(string)$key,$m)) return sanitize_text_field((string)$key);
    return ((int)$m[1]+(int)$years).'-'.$m[2];
}

function bkp_rollover_shift_month_rows($rows,$years=1) {
    $out=array();
    foreach((array)$rows as $row) {
        $key=bkp_rollover_shift_month_key($row['key']??'',$years);
        if(!preg_match('/^(\d{4})-(\d{2})$/',$key,$m)) continue;
        $out[]=array('key'=>$key,'label'=>bkp_rollover_month_name((int)$m[2]),'year'=>(int)$m[1]);
    }
    return $out;
}

function bkp_rollover_build_preview($context) {
    $target=bkp_rollover_parse_season($context['target_season']??'');
    $old=bkp_rollover_date($context['old_carnival_sunday']??'');
    $new=bkp_rollover_date($context['new_carnival_sunday']??'');
    if(!$old || !$new) return new WP_Error('bkp_rollover_dates','Vul geldige carnavalszondagen in.');
    if((int)$new->format('Y')!==$target['end']) return new WP_Error('bkp_rollover_year','De nieuwe carnavalszondag moet in het eindjaar van het nieuwe seizoen vallen.');

    $rows=array();
    foreach(bkp_projects(true) as $post) {
        if(get_post_meta($post->ID,'_bkp_project_recurring',true)!=='1') continue;
        bkp_rollover_add_preview_row($rows,$post,'_bkp_project_start','Projecten','Startdatum',$old->format('Y-m-d'),$new->format('Y-m-d'));
        bkp_rollover_add_preview_row($rows,$post,'_bkp_project_end','Projecten','Einddatum',$old->format('Y-m-d'),$new->format('Y-m-d'));
    }
    foreach(bkp_events() as $post) bkp_rollover_add_preview_row($rows,$post,'_bkp_date','Jaarplanning','Datum',$old->format('Y-m-d'),$new->format('Y-m-d'));
    foreach(bkp_tasks('Projectplanning') as $post) bkp_rollover_add_preview_row($rows,$post,'_bkp_due_date','Projectplanning','Deadline',$old->format('Y-m-d'),$new->format('Y-m-d'));
    foreach(bkp_tasks('To-do') as $post) bkp_rollover_add_preview_row($rows,$post,'_bkp_due_date','To-do','Deadline',$old->format('Y-m-d'),$new->format('Y-m-d'));
    foreach(bkp_inventories() as $post) bkp_rollover_add_preview_row($rows,$post,'_bkp_due_date','Inventarisaties','Sluitdatum',$old->format('Y-m-d'),$new->format('Y-m-d'));
    foreach(bkp_actions() as $post) {
        bkp_rollover_add_preview_row($rows,$post,'_bkp_date','Acties en besluiten','Datum',$old->format('Y-m-d'),$new->format('Y-m-d'));
        bkp_rollover_add_preview_row($rows,$post,'_bkp_due_date','Acties en besluiten','Einddatum',$old->format('Y-m-d'),$new->format('Y-m-d'));
    }

    // Eigen deadlines uit de losse herinneringsplugin worden eveneens als
    // controleerbare regels in het voorstel opgenomen. Zonder eigen deadline
    // volgt de herinnering automatisch de datum van het planningitem.
    $reminder_posts=get_posts(array(
        'post_type'=>array('bkp_project','bkp_event','bkp_task','bkp_inventory','bkp_action'),
        'post_status'=>'any',
        'posts_per_page'=>-1,
    ));
    foreach($reminder_posts as $post) {
        if(!metadata_exists('post',$post->ID,'_bkpr_deadline')) continue;
        $deadline=(string)get_post_meta($post->ID,'_bkpr_deadline',true);
        if($deadline==='' || !bkp_rollover_date($deadline)) continue;
        bkp_rollover_add_preview_row($rows,$post,'_bkpr_deadline','Herinneringsmails','Eigen deadline',$old->format('Y-m-d'),$new->format('Y-m-d'));
    }

    $new_dates=bkp_rollover_carnival_dates($target['end']);
    // A manually supplied Sunday is leading. Rebuild the surrounding days from it.
    $new_dates['sunday']=$new;
    $new_dates['saturday']=$new->modify('-1 day');
    $new_dates['monday']=$new->modify('+1 day');
    $new_dates['tuesday']=$new->modify('+2 days');
    $new_dates['ash_wednesday']=$new->modify('+3 days');

    return array(
        'created_at'=>current_time('mysql'),
        'current_season'=>sanitize_text_field((string)($context['current_season']??get_option('bkp_season',''))),
        'target_season'=>$target['start'].'-'.$target['end'],
        'old_carnival_sunday'=>$old->format('Y-m-d'),
        'new_carnival_sunday'=>$new->format('Y-m-d'),
        'carnival_period'=>sanitize_text_field((string)($context['carnival_period']??bkp_rollover_period_label($new_dates))),
        'carnival_year'=>$target['end'],
        'rows'=>$rows,
        'current_months'=>bkp_rollover_current_month_rows(),
        'new_months'=>bkp_rollover_shift_month_rows(bkp_rollover_current_month_rows(),1),
        'source_check'=>bkp_rollover_source_check($target['end'],$new_dates),
        'dates'=>$new_dates,
    );
}

function bkp_rollover_transient_key() {
    return 'bkp_rollover_preview_'.get_current_user_id();
}

function bkp_rollover_capture_meta($post_id,$keys) {
    $out=array();
    foreach($keys as $key) $out[$key]=array('exists'=>metadata_exists('post',$post_id,$key),'value'=>get_post_meta($post_id,$key,true));
    return $out;
}

function bkp_rollover_create_snapshot() {
    $snapshot=array(
        'id'=>str_replace('-','',wp_generate_uuid4()),
        'created_at'=>current_time('mysql'),
        'season'=>(string)get_option('bkp_season',''),
        'options'=>array(
            'bkp_season'=>get_option('bkp_season',''),
            'bkp_carnival_period'=>get_option('bkp_carnival_period',''),
            'bkp_carnival_sunday'=>get_option('bkp_carnival_sunday',''),
            'bkp_months'=>get_option('bkp_months',array()),
            'bkp_last_rollover'=>get_option('bkp_last_rollover',array()),
        ),
        'posts'=>array(),
    );
    // Neem ook eigen herinneringsdeadlines en verzendhistorie mee. Daardoor
    // herstelt een seizoensback-up de herinneringsinstellingen exact mee.
    $keys=array('_bkp_date','_bkp_due_date','_bkp_months','_bkp_period','_bkp_inventory_open','_bkp_task_status','_bkp_done','_bkpr_deadline','_bkpr_sent_signatures','_bkpp_sent_signatures','_bkp_project_start','_bkp_project_end','_bkp_project_season','_bkp_project_status');
    $posts=get_posts(array('post_type'=>array('bkp_project','bkp_event','bkp_task','bkp_inventory','bkp_action'),'post_status'=>'any','posts_per_page'=>-1));
    foreach($posts as $post) $snapshot['posts'][]=array('id'=>(int)$post->ID,'post_type'=>$post->post_type,'meta'=>bkp_rollover_capture_meta($post->ID,$keys));
    $snapshots=(array)get_option('bkp_season_snapshots',array());
    array_unshift($snapshots,$snapshot);
    $snapshots=array_slice($snapshots,0,5);
    update_option('bkp_season_snapshots',$snapshots,false);
    return $snapshot;
}

function bkp_rollover_restore_snapshot($snapshot_id='') {
    $snapshots=(array)get_option('bkp_season_snapshots',array());
    $snapshot=null;
    foreach($snapshots as $candidate) {
        if($snapshot_id==='' || hash_equals((string)($candidate['id']??''),(string)$snapshot_id)) { $snapshot=$candidate; break; }
    }
    if(!$snapshot) return new WP_Error('bkp_snapshot','Er is geen passende seizoensback-up gevonden.');
    foreach((array)($snapshot['options']??array()) as $key=>$value) update_option(sanitize_key($key),$value,false);
    foreach((array)($snapshot['posts']??array()) as $record) {
        $post_id=absint($record['id']??0);
        if(!$post_id || !get_post($post_id)) continue;
        foreach((array)($record['meta']??array()) as $key=>$meta) {
            $key=sanitize_key($key);
            if(!empty($meta['exists'])) update_post_meta($post_id,$key,$meta['value']??'');
            else delete_post_meta($post_id,$key);
        }
    }
    update_option('bkp_last_rollover_restore',array('snapshot_id'=>$snapshot['id']??'','restored_at'=>current_time('mysql')),false);
    return $snapshot;
}


/**
 * Alleen statussen die echt als afgerond gelden worden bij een seizoenswissel
 * opnieuw geopend. Geannuleerd en gearchiveerd blijven bewust onaangeroerd.
 */
function bkp_rollover_status_is_completed($status) {
    return in_array(strtolower(trim((string)$status)), array('ja','af','afgerond','gereed','klaar','done','voltooid'), true);
}

/**
 * Zet afgeronde werkonderdelen terug naar hun standaard startstatus.
 * Activiteiten hebben geen 'afgerond'-status en worden daarom niet gewijzigd.
 */
function bkp_rollover_reset_completed_items() {
    $result=array(
        'project_tasks'=>0,
        'inventories'=>0,
        'todos'=>0,
        'actions'=>0,
        'total'=>0,
    );

    foreach(bkp_tasks('Projectplanning') as $task) {
        $status=(string)get_post_meta($task->ID,'_bkp_task_status',true);
        if(!bkp_rollover_status_is_completed($status)) continue;
        update_post_meta($task->ID,'_bkp_task_status',bkp_status_default('project'));
        $result['project_tasks']++;
    }

    foreach(bkp_inventories() as $inventory) {
        $status=(string)get_post_meta($inventory->ID,'_bkp_task_status',true);
        if(!bkp_rollover_status_is_completed($status)) continue;
        update_post_meta($inventory->ID,'_bkp_task_status',bkp_status_default('inventories'));
        $result['inventories']++;
    }

    foreach(bkp_tasks('To-do') as $task) {
        $status=(string)get_post_meta($task->ID,'_bkp_task_status',true);
        if(!bkp_rollover_status_is_completed($status)) continue;
        update_post_meta($task->ID,'_bkp_task_status',bkp_status_default('todos'));
        $result['todos']++;
    }

    foreach(bkp_actions() as $action) {
        $status=(string)get_post_meta($action->ID,'_bkp_done',true);
        if(!bkp_rollover_status_is_completed($status)) continue;
        update_post_meta($action->ID,'_bkp_done',bkp_status_default('actions'));
        $result['actions']++;
    }

    $result['total']=$result['project_tasks']+$result['inventories']+$result['todos']+$result['actions'];
    return $result;
}

function bkp_rollover_apply_project_months($new_month_rows) {
    update_option('bkp_months',array_values((array)$new_month_rows),false);
    $count=0;
    foreach(bkp_tasks('Projectplanning') as $task) {
        $current=array_filter(explode(',',(string)get_post_meta($task->ID,'_bkp_months',true)));
        $shifted=array();
        foreach($current as $key) $shifted[]=bkp_rollover_shift_month_key($key,1);
        $shifted=array_values(array_unique(array_filter($shifted)));
        update_post_meta($task->ID,'_bkp_months',implode(',',$shifted));
        update_post_meta($task->ID,'_bkp_period',bkp_admin_month_period($shifted));
        $count++;
    }
    return $count;
}

function bkp_rollover_apply_projects($target_season, $reset_completed=false) {
    $updated=0; $reset=0;
    foreach(bkp_projects(true) as $project) {
        if(get_post_meta($project->ID,'_bkp_project_recurring',true)!=='1') continue;
        update_post_meta($project->ID,'_bkp_project_season',sanitize_text_field((string)$target_season));
        $updated++;
        if($reset_completed && bkp_rollover_status_is_completed(bkp_project_status($project->ID))) {
            update_post_meta($project->ID,'_bkp_project_status',bkp_status_default('projects'));
            $reset++;
        }
    }
    return array('updated'=>$updated,'reset'=>$reset);
}

function bkp_rollover_handle_actions() {
    if(!is_admin() || empty($_POST['bkp_rollover_action'])) return;
    if(!current_user_can('manage_options')) wp_die('Geen toegang.');
    $action=sanitize_key(wp_unslash($_POST['bkp_rollover_action']));
    $redirect=admin_url('admin.php?page=bkp-new-season');

    if($action==='preview') {
        check_admin_referer('bkp_rollover_preview');
        $context=array(
            'current_season'=>sanitize_text_field(wp_unslash($_POST['current_season']??'')),
            'target_season'=>sanitize_text_field(wp_unslash($_POST['target_season']??'')),
            'old_carnival_sunday'=>sanitize_text_field(wp_unslash($_POST['old_carnival_sunday']??'')),
            'new_carnival_sunday'=>sanitize_text_field(wp_unslash($_POST['new_carnival_sunday']??'')),
            'carnival_period'=>sanitize_text_field(wp_unslash($_POST['carnival_period']??'')),
        );
        $preview=bkp_rollover_build_preview($context);
        if(is_wp_error($preview)) bkp_admin_set_notice('error',$preview->get_error_message());
        else {
            set_transient(bkp_rollover_transient_key(),$preview,HOUR_IN_SECONDS);
            bkp_admin_set_notice('success','Het voorstel is gemaakt. Controleer alle regels voordat je het toepast.');
            $redirect=add_query_arg('proposal','1',$redirect);
        }
        wp_safe_redirect($redirect); exit;
    }

    if($action==='discard') {
        check_admin_referer('bkp_rollover_discard');
        delete_transient(bkp_rollover_transient_key());
        bkp_admin_set_notice('success','Het voorstel is verwijderd. Er zijn geen gegevens gewijzigd.');
        wp_safe_redirect($redirect); exit;
    }

    if($action==='apply') {
        check_admin_referer('bkp_rollover_apply');
        $preview=get_transient(bkp_rollover_transient_key());
        if(!is_array($preview)) {
            bkp_admin_set_notice('error','Het voorstel is verlopen. Maak eerst een nieuw voorstel.');
            wp_safe_redirect($redirect); exit;
        }
        if(empty($_POST['confirmed'])) {
            bkp_admin_set_notice('error','Vink eerst aan dat je het voorstel hebt gecontroleerd.');
            wp_safe_redirect(add_query_arg('proposal','1',$redirect)); exit;
        }
        $allowed=array();
        foreach((array)$preview['rows'] as $row) $allowed[$row['key']]=$row;
        $posted=isset($_POST['rows'])?(array)wp_unslash($_POST['rows']):array();
        $snapshot=bkp_rollover_create_snapshot();
        $inventory_responses_archived=function_exists('bkp_inventory_response_count_for_season')?bkp_inventory_response_count_for_season($preview['current_season']??''):0;
        $changed=0;
        $reminder_changed=0;
        $changed_posts=array();
        foreach($posted as $key=>$entry) {
            $key=sanitize_key($key);
            if(empty($allowed[$key]) || empty($entry['include'])) continue;
            $row=$allowed[$key];
            $date=sanitize_text_field($entry['date']??'');
            if(!bkp_rollover_date($date)) continue;
            $post_id=absint($row['post_id']);
            if(!$post_id || get_post_type($post_id)!==$row['post_type']) continue;
            if(!in_array($row['meta_key'],array('_bkp_date','_bkp_due_date','_bkpr_deadline','_bkp_project_start','_bkp_project_end'),true)) continue;
            $old_value=(string)get_post_meta($post_id,$row['meta_key'],true);
            if($old_value===$date) continue;
            update_post_meta($post_id,$row['meta_key'],$date);
            $changed++;
            $changed_posts[$post_id]=true;
            if($row['meta_key']==='_bkpr_deadline') $reminder_changed++;
        }
        $project_count=0;
        if(!empty($_POST['shift_project_months'])) $project_count=bkp_rollover_apply_project_months($preview['new_months']??array());

        // Een nieuw seizoen kan met een schone statuslei beginnen. Dit gebeurt
        // uitsluitend na de expliciete, standaard aangevinkte keuze in het
        // bevestigingsscherm. Geannuleerde/gearchiveerde items blijven intact.
        $reset_completed=!empty($_POST['reset_completed_items']);
        $status_resets=array('project_tasks'=>0,'inventories'=>0,'todos'=>0,'actions'=>0,'total'=>0);
        if($reset_completed) $status_resets=bkp_rollover_reset_completed_items();

        $project_rollover=array('updated'=>0,'reset'=>0);
        if(!empty($_POST['carry_projects'])) $project_rollover=bkp_rollover_apply_projects($preview['target_season']??'',$reset_completed);

        // Het nieuwe seizoen begint met lege, gesloten inventarisaties. De
        // oude antwoorden blijven als seizoensarchief bewaard en worden bij
        // herstel van de seizoensback-up automatisch weer zichtbaar.
        $inventories_closed=0;
        foreach(bkp_inventories() as $inventory) {
            update_post_meta($inventory->ID,'_bkp_inventory_open','0');
            $inventories_closed++;
        }

        // Oude verzendhandtekeningen horen bij de vorige deadline. Wis ze
        // uitsluitend voor items waarvan een datum daadwerkelijk is veranderd.
        $history_reset=0;
        $push_history_reset=0;
        foreach(array_keys($changed_posts) as $post_id) {
            if(metadata_exists('post',$post_id,'_bkpr_sent_signatures')) {
                delete_post_meta($post_id,'_bkpr_sent_signatures');
                $history_reset++;
            }
            if(metadata_exists('post',$post_id,'_bkpp_sent_signatures')) {
                delete_post_meta($post_id,'_bkpp_sent_signatures');
                $push_history_reset++;
            }
        }

        update_option('bkp_season',sanitize_text_field((string)$preview['target_season']),false);
        update_option('bkp_carnival_sunday',sanitize_text_field((string)$preview['new_carnival_sunday']),false);
        update_option('bkp_carnival_period',sanitize_text_field((string)$preview['carnival_period']),false);
        $rollover_result=array(
            'from'=>$preview['current_season']??'',
            'to'=>$preview['target_season']??'',
            'old_carnival_sunday'=>$preview['old_carnival_sunday']??'',
            'new_carnival_sunday'=>$preview['new_carnival_sunday']??'',
            'applied_at'=>current_time('mysql'),
            'changed_dates'=>$changed,
            'reminder_deadlines'=>$reminder_changed,
            'reminder_histories_reset'=>$history_reset,
            'push_histories_reset'=>$push_history_reset,
            'changed_post_ids'=>array_map('absint',array_keys($changed_posts)),
            'project_tasks'=>$project_count,
            'projects_updated'=>$project_rollover['updated'],
            'project_statuses_reset'=>$project_rollover['reset'],
            'completed_statuses_reset'=>$status_resets,
            'inventory_responses_archived'=>$inventory_responses_archived,
            'inventories_closed'=>$inventories_closed,
            'snapshot_id'=>$snapshot['id']??'',
            'user_id'=>get_current_user_id(),
        );
        update_option('bkp_last_rollover',$rollover_result,false);
        do_action('bkp_after_season_rollover',$rollover_result);
        delete_transient(bkp_rollover_transient_key());
        $extra=$reminder_changed?' Daarvan '.number_format_i18n($reminder_changed).' eigen herinneringsdeadline(s).':'';
        $inventory_extra=' '.number_format_i18n($inventory_responses_archived).' inventarisatiereactie(s) zijn als vorig-seizoensarchief bewaard en '.number_format_i18n($inventories_closed).' inventarisatie(s) zijn gesloten klaargezet.';
        $project_extra=$project_rollover['updated']?' '.number_format_i18n($project_rollover['updated']).' terugkerende project(en) zijn naar het nieuwe seizoen gezet'.($project_rollover['reset']?' en '.number_format_i18n($project_rollover['reset']).' afgeronde projectstatus(sen) zijn teruggezet naar Voorbereiding':'').'.':'';
        $status_extra=$reset_completed?' '.number_format_i18n($status_resets['total']).' afgeronde werkonderde(e)l(en) zijn opnieuw geopend'.($status_resets['total']?' (projectplanning '.number_format_i18n($status_resets['project_tasks']).', inventarisaties '.number_format_i18n($status_resets['inventories']).', to-do '.number_format_i18n($status_resets['todos']).', acties/besluiten '.number_format_i18n($status_resets['actions']).')':'').'.':'';
        bkp_admin_set_notice('success','Nieuw seizoen '.$preview['target_season'].' is klaargezet: '.$changed.' datumveld(en) aangepast'.($project_count?' en '.$project_count.' projecttaak/taken doorgeschoven':'').'.'.$project_extra.$status_extra.$extra.$inventory_extra);
        wp_safe_redirect($redirect); exit;
    }

    if($action==='restore') {
        check_admin_referer('bkp_rollover_restore');
        $snapshot_id=sanitize_key(wp_unslash($_POST['snapshot_id']??''));
        $result=bkp_rollover_restore_snapshot($snapshot_id);
        if(is_wp_error($result)) bkp_admin_set_notice('error',$result->get_error_message());
        else {
            do_action('bkp_after_season_restore',$result);
            bkp_admin_set_notice('success','De seizoensback-up van '.($result['season']??'het vorige seizoen').' is hersteld, inclusief werkstatussen, inventarisatiestatussen, eventuele eigen herinneringsdeadlines en verzendhistorie. De reacties van dat seizoen zijn weer zichtbaar.');
        }
        wp_safe_redirect($redirect); exit;
    }
}
add_action('admin_init','bkp_rollover_handle_actions',15);

function bkp_rollover_render_date_summary($dates) {
    echo '<div class="bkp-season-date-cards">';
    $labels=array('saturday'=>'Carnavalszaterdag','sunday'=>'Carnavalszondag','monday'=>'Carnavalsmaandag','tuesday'=>'Carnavalsdinsdag','ash_wednesday'=>'Aswoensdag','easter'=>'Pasen');
    foreach($labels as $key=>$label) {
        if(empty($dates[$key]) || !$dates[$key] instanceof DateTimeInterface) continue;
        echo '<div class="bkp-season-date-card"><span>'.esc_html($label).'</span><strong>'.esc_html(bkp_rollover_dutch_date($dates[$key],true)).'</strong></div>';
    }
    echo '</div>';
}

function bkp_admin_new_season_page() {
    if(!current_user_can('manage_options')) return;
    $defaults=bkp_rollover_default_context();
    $preview=get_transient(bkp_rollover_transient_key());
    $snapshots=(array)get_option('bkp_season_snapshots',array());
    $last=get_option('bkp_last_rollover',array());

    echo '<div class="wrap bkp-admin-wrap bkp-season-admin"><h1>Nieuw seizoen klaarzetten</h1>'; bkp_admin_show_notice();
    echo '<p class="bkp-admin-lead">Maak eerst een controleerbaar voorstel. Pas na een tweede, uitdrukkelijke bevestiging worden datums, projectlooptijden, eventuele eigen herinneringsdeadlines en desgewenst de projectmaanden aangepast. Inventarisaties beginnen in het nieuwe seizoen leeg en gesloten. Afgeronde werkonderdelen kunnen in dezelfde stap terug naar hun startstatus, zodat het nieuwe seizoen met een schone statuslei begint. Deze functie importeert niets en wijzigt geen namen, teksten, verantwoordelijken, commissies, documenten of bestuursgegevens.</p>';
    echo '<div class="notice notice-warning inline"><p><strong>Veilige werkwijze:</strong> het systeem maakt vlak vóór toepassen automatisch een seizoensback-up. Alle voorgestelde datums blijven vooraf handmatig wijzigbaar of uitschakelbaar.</p></div>';

    if(is_array($last) && !empty($last['applied_at'])) echo '<p class="bkp-season-last"><strong>Laatst uitgevoerd:</strong> '.esc_html(($last['from']??'').' → '.($last['to']??'')).' op '.esc_html(mysql2date('d-m-Y H:i',(string)$last['applied_at'])).'.</p>';

    echo '<section class="bkp-admin-panel"><h2>1. Voorstel instellen</h2><form method="post" class="bkp-season-settings">';
    wp_nonce_field('bkp_rollover_preview');
    echo '<input type="hidden" name="bkp_rollover_action" value="preview"><div class="bkp-season-fields">';
    echo '<label>Huidig seizoen<input name="current_season" value="'.esc_attr(is_array($preview)?$preview['current_season']:$defaults['current_season']).'" required pattern="[0-9]{4}-[0-9]{4}"></label>';
    echo '<label>Nieuw seizoen<input name="target_season" value="'.esc_attr(is_array($preview)?$preview['target_season']:$defaults['target_season']).'" required pattern="[0-9]{4}-[0-9]{4}"></label>';
    echo '<label>Huidige carnavalszondag<input type="date" name="old_carnival_sunday" value="'.esc_attr(is_array($preview)?$preview['old_carnival_sunday']:$defaults['old_carnival_sunday']).'" required></label>';
    echo '<label>Nieuwe carnavalszondag<input type="date" name="new_carnival_sunday" value="'.esc_attr(is_array($preview)?$preview['new_carnival_sunday']:$defaults['new_carnival_sunday']).'" required></label>';
    echo '<label class="bkp-season-span-2">Tekst carnavalperiode<input name="carnival_period" value="'.esc_attr(is_array($preview)?$preview['carnival_period']:$defaults['carnival_period']).'" required></label>';
    echo '</div><p class="description">Standaard worden de carnavalsdata kalenderkundig afgeleid van Pasen. Activiteiten van 70 dagen vóór tot en met 21 dagen na carnavalszondag behouden dezelfde afstand tot carnaval; overige datums schuiven één kalenderjaar op.</p>';
    submit_button(is_array($preview)?'Voorstel opnieuw berekenen':'Voorstel maken','primary','submit',false);
    echo '</form></section>';

    if(is_array($preview)) {
        echo '<section class="bkp-admin-panel"><div class="bkp-season-heading"><div><h2>2. Voorstel controleren</h2><p class="description">'.count($preview['rows']).' datumvelden gevonden. Pas datums aan of zet regels uit.</p></div><form method="post">';
        wp_nonce_field('bkp_rollover_discard'); echo '<input type="hidden" name="bkp_rollover_action" value="discard"><button class="button" type="submit">Voorstel verwijderen</button></form></div>';
        bkp_rollover_render_date_summary($preview['dates']);
        $principles=(array)get_option('bkp_principles',array());
        if($principles) {
            echo '<details class="bkp-season-principles"><summary>Uitgangspunten erbij houden ('.count($principles).')</summary><div class="bkp-season-principle-list">';
            foreach($principles as $principle) {
                $title=sanitize_text_field((string)($principle['title']??''));
                $value=sanitize_text_field((string)($principle['value']??''));
                $note=sanitize_textarea_field((string)($principle['note']??''));
                if($title==='' && $value==='' && $note==='') continue;
                echo '<div><strong>'.esc_html($title?:'Uitgangspunt').'</strong>';
                if($value!=='') echo '<span>'.esc_html($value).'</span>';
                if($note!=='') echo '<small>'.nl2br(esc_html($note)).'</small>';
                echo '</div>';
            }
            echo '</div></details>';
        }
        $source=$preview['source_check']??array();
        $source_class=($source['status']??'')==='confirmed'?'notice-success':(($source['status']??'')==='different'?'notice-warning':'notice-info');
        echo '<div class="notice '.esc_attr($source_class).' inline"><p>'.esc_html($source['message']??''). ' <a href="'.esc_url($source['url']??'').'" target="_blank" rel="noopener">Online bron openen</a>.</p></div>';
        echo '<form method="post" class="bkp-season-apply-form">'; wp_nonce_field('bkp_rollover_apply'); echo '<input type="hidden" name="bkp_rollover_action" value="apply">';
        echo '<div class="bkp-grid-scroll"><table class="widefat striped bkp-edit-grid bkp-season-preview-table"><thead><tr><th>Meenemen</th><th>Onderdeel</th><th>Item</th><th>Veld</th><th>Huidig</th><th>Voorstel</th><th>Regel</th></tr></thead><tbody>';
        foreach($preview['rows'] as $row) {
            $key=$row['key'];
            echo '<tr><td><label class="bkp-switch"><input type="checkbox" name="rows['.esc_attr($key).'][include]" value="1" checked><span></span></label></td><td>'.esc_html($row['section']).'</td><td><strong>'.esc_html($row['title']).'</strong></td><td>'.esc_html($row['field']).'</td><td><code>'.esc_html($row['old']).'</code></td><td><input type="date" name="rows['.esc_attr($key).'][date]" value="'.esc_attr($row['new']).'" required></td><td><small>'.esc_html($row['reason']).'</small></td></tr>';
        }
        if(empty($preview['rows'])) echo '<tr><td colspan="7">Er zijn geen bestaande exacte datums gevonden om door te schuiven.</td></tr>';
        echo '</tbody></table></div>';
        echo '<div class="bkp-season-project-option"><label><input type="checkbox" name="shift_project_months" value="1" checked> <strong>Projectplanning één seizoen doorschuiven</strong></label><p class="description">De maandkolommen en aangevinkte projectmaanden schuiven één jaar op. Taken, teksten, volgorde, statussen en verantwoordelijken blijven gelijk. Eigen deadlines uit de herinneringsplugin staan afzonderlijk in de tabel hierboven en zijn daar controleerbaar.</p>';
        $inventory_archive_count=function_exists('bkp_inventory_response_count_for_season')?bkp_inventory_response_count_for_season($preview['current_season']??''):0;
        echo '<div class="bkp-season-project-carry"><label><input type="checkbox" name="carry_projects" value="1" checked> <strong>Terugkerende projecten meenemen naar '.esc_html($preview['target_season']).'</strong></label><p class="description">Projectkoppelingen, projectleden en projectleiders blijven behouden. Alleen projecten met Terugkerend aangevinkt krijgen het nieuwe seizoen.</p></div>';
        echo '<div class="bkp-season-project-carry"><label><input type="checkbox" name="reset_completed_items" value="1" checked> <strong>Afgeronde onderdelen opnieuw openen voor het nieuwe seizoen</strong></label><p class="description">Zet alleen onderdelen met een echte afgerond-status terug naar de startstatus: terugkerende projecten → Voorbereiding, projectplanning → Nog in te plannen, inventarisaties → Voorbereiding, to-do → Open en acties/besluiten → Open. Geannuleerd en gearchiveerd blijven ongewijzigd. Activiteiten worden niet aangepast.</p></div>';
        echo '<div class="notice notice-info inline"><p><strong>Inventarisaties:</strong> het nieuwe seizoen start met 0 reacties en alle inventarisaties worden gesloten. '.esc_html(number_format_i18n($inventory_archive_count)).' bestaande reactie(s) blijven veilig bewaard bij het vorige seizoen en kunnen via een seizoensherstel terugkomen.</p></div>';
        if(!empty($preview['current_months']) && !empty($preview['new_months'])) {
            $old_first=reset($preview['current_months']);$old_last=end($preview['current_months']);$new_first=reset($preview['new_months']);$new_last=end($preview['new_months']);
            echo '<p><code>'.esc_html(($old_first['key']??'').' – '.($old_last['key']??'')).'</code> wordt <code>'.esc_html(($new_first['key']??'').' – '.($new_last['key']??'')).'</code>.</p>';
        }
        echo '</div>';
        echo '<div class="bkp-season-confirm"><label><input type="checkbox" name="confirmed" value="1" required> Ik heb de carnavalsdata en alle voorgestelde datums gecontroleerd en wil dit seizoen nu toepassen.</label></div>';
        echo '<div class="bkp-save-bar"><span>Vlak vóór toepassen wordt automatisch een herstelbare back-up opgeslagen.</span><button class="button button-primary button-hero" type="submit">Nieuw seizoen definitief klaarzetten</button></div></form></section>';
    }

    echo '<section class="bkp-admin-panel"><h2>Seizoensback-ups</h2>';
    if(!$snapshots) echo '<p>Er is nog geen seizoensback-up. De eerste wordt automatisch gemaakt wanneer je een voorstel toepast.</p>';
    else {
        echo '<p>Hiermee herstel je seizoeninstellingen, datum-/maandvelden, werkstatussen en de open/gesloten status van inventarisaties. Reacties zijn per seizoen opgeslagen en worden bij herstel automatisch weer zichtbaar.</p><div class="bkp-season-snapshots">';
        foreach($snapshots as $snapshot) {
            echo '<form method="post" class="bkp-season-snapshot">'; wp_nonce_field('bkp_rollover_restore');
            echo '<input type="hidden" name="bkp_rollover_action" value="restore"><input type="hidden" name="snapshot_id" value="'.esc_attr($snapshot['id']??'').'">';
            echo '<div><strong>'.esc_html($snapshot['season']??'Onbekend seizoen').'</strong><span>'.esc_html(mysql2date('d-m-Y H:i',(string)($snapshot['created_at']??''))).'</span></div><button type="submit" class="button" onclick="return confirm(\'Deze datum- en maandvelden herstellen naar de gekozen back-up?\')">Deze back-up herstellen</button></form>';
        }
        echo '</div>';
    }
    echo '</section></div>';
}
