<?php
$responsibility_item = isset($_GET['bkp_responsibility_item']) ? absint($_GET['bkp_responsibility_item']) : 0;
if ($responsibility_item) { bkp_render_responsibility_detail($responsibility_item); return; }

$edit_section = isset($_GET['bkp_edit']) ? sanitize_key(wp_unslash($_GET['bkp_edit'])) : '';
if ($edit_section !== '') { bkp_render_frontend_editor($edit_section); return; }

$projects    = bkp_projects(false);
$events      = bkp_events();
$project     = bkp_tasks('Projectplanning');
$todos       = bkp_tasks('To-do');
$inventories = bkp_inventories();
$actions     = bkp_actions();
$committees  = bkp_committee_groups();
$documents   = bkp_documents(true);
$board       = get_option('bkp_board', array());
$contact     = get_option('bkp_contact', '');
$contact_details = (array) get_option('bkp_contact_details', array());
$months      = get_option('bkp_months', array());

$dated = 0; $confirmed = 0; $undated = 0;
foreach ($events as $event) {
    if (get_post_meta($event->ID, '_bkp_date', true)) $dated++; else $undated++;
    if (get_post_meta($event->ID, '_bkp_confirmed', true) === '1') $confirmed++;
}
$open_work = 0;
foreach ($inventories as $item) if (bkp_inventory_is_open($item)) $open_work++;
foreach ($todos as $item) if (!bkp_is_done(get_post_meta($item->ID, '_bkp_task_status', true))) $open_work++;
foreach ($actions as $item) if (!bkp_is_done(get_post_meta($item->ID, '_bkp_done', true))) $open_work++;

$intro = get_option('bkp_intro_text', 'Alle activiteiten, taken, inventarisaties en aandachtspunten voor het carnavalsseizoen op één plek.');
$responsibility_groups = is_user_logged_in() ? bkp_responsibility_groups() : array('overdue'=>array(),'soon'=>array(),'later'=>array(),'undated'=>array(),'completed'=>array());
$responsibility_open_count = is_user_logged_in()
    ? count($responsibility_groups['overdue']) + count($responsibility_groups['soon']) + count($responsibility_groups['later']) + count($responsibility_groups['undated'])
    : 0;

$responsibility_all_items = array();
$responsibility_filter_projects = array();
$responsibility_filter_sections = array();
$responsibility_has_unlinked = false;
if (is_user_logged_in()) {
    foreach ($responsibility_groups as $group_items) {
        foreach ($group_items as $responsibility_filter_item) {
            $responsibility_all_items[] = $responsibility_filter_item;
            $project_id = absint($responsibility_filter_item['project_id'] ?? 0);
            if ($project_id) {
                $responsibility_filter_projects[$project_id] = (string) ($responsibility_filter_item['project_name'] ?? '');
            } else {
                $responsibility_has_unlinked = true;
            }
            $section_key = (string) ($responsibility_filter_item['section'] ?? '');
            if ($section_key !== '') $responsibility_filter_sections[$section_key] = (string) ($responsibility_filter_item['section_label'] ?? $section_key);
        }
    }
    natcasesort($responsibility_filter_projects);
    natcasesort($responsibility_filter_sections);
}
$responsibility_total_count = count($responsibility_all_items);

$upcoming_events = array();
$today = wp_date('Y-m-d');
foreach ($events as $event) {
    $event_date = (string) get_post_meta($event->ID, '_bkp_date', true);
    if ($event_date !== '' && $event_date >= $today) {
        $upcoming_events[] = $event;
        if (count($upcoming_events) >= 5) break;
    }
}

$project_filter_options = array();
foreach ($projects as $project_item) $project_filter_options[$project_item->ID] = $project_item->post_title;
$has_unlinked_todos = false;
foreach ($todos as $todo_item) if (!bkp_get_project_id($todo_item->ID)) { $has_unlinked_todos = true; break; }
?>
<section class="bkp-hero">
    <div class="bkp-wrap">
        <div class="bkp-eyebrow">Carnavalsseizoen <?php echo esc_html(get_option('bkp_season', '2026-2027')); ?></div>
        <h1>Jaarplanning De Billekletsers</h1>
        <p><?php echo esc_html($intro); ?></p>
        <span class="bkp-hero-note">Carnaval: <?php echo esc_html(get_option('bkp_carnival_period', '')); ?></span>
    </div>
</section>

<div class="bkp-wrap">
    <section class="bkp-summary">
        <div class="bkp-stat"><b><?php echo count($projects); ?></b><span>projecten</span></div>
        <div class="bkp-stat"><b><?php echo count($events); ?></b><span>activiteiten</span></div>
        <div class="bkp-stat"><b><?php echo $dated; ?></b><span>met datum</span></div>
        <div class="bkp-stat"><b><?php echo $open_work; ?></b><span>open werkpunten</span></div>
    </section>
</div>

<main class="bkp-main"><div class="bkp-wrap">
<nav class="bkp-primary-nav" aria-label="Hoofdnavigatie jaarplanner">
    <button class="bkp-tab bkp-tab--primary" type="button" aria-selected="true" data-tab="overview">Overzicht</button>
    <?php if (is_user_logged_in()): ?>
        <button class="bkp-tab bkp-tab--primary" type="button" aria-selected="false" data-tab="projects">Projecten</button>
    <?php endif; ?>
    <button class="bkp-tab bkp-tab--primary" type="button" aria-selected="false" data-tab="agenda">Jaarplanning</button>
    <?php if (is_user_logged_in()): ?>
        <button class="bkp-tab bkp-tab--primary" type="button" aria-selected="false" data-tab="responsibilities">Mijn verantwoordelijkheden<?php if ($responsibility_open_count): ?><span class="bkp-nav-count"><?php echo esc_html($responsibility_open_count); ?></span><?php endif; ?></button>
        <?php do_action('bkp_personal_nav_items'); ?>
        <details class="bkp-nav-group"><summary>Werk &amp; taken</summary><div class="bkp-nav-submenu">
            <button type="button" data-tab="project">Projectplanning</button>
            <button type="button" data-tab="inventory">Inventarisaties</button>
            <button type="button" data-tab="todo">To-do</button>
            <button type="button" data-tab="actions">Acties &amp; besluiten</button>
        </div></details>
    <?php endif; ?>
    <details class="bkp-nav-group"><summary>Vereniging</summary><div class="bkp-nav-submenu">
        <button type="button" data-tab="committees">Commissies</button>
        <button type="button" data-tab="documents">Documenten</button>
        <button type="button" data-tab="info">Bestuur &amp; contact</button>
    </div></details>
</nav>

<section class="bkp-panel is-active" data-panel="overview">
    <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Snel overzicht</span><h2 class="bkp-section-title">Wat staat er op stapel?</h2></div></div>
    <?php if (is_user_logged_in()): ?>
    <div class="bkp-overview-grid">
        <a class="bkp-card bkp-overview-card" data-open-tab="projects" href="#projects"><strong><?php echo esc_html(count($projects)); ?></strong><span>projecten</span><small>Voortgang per hoofdonderwerp</small></a>
        <a class="bkp-card bkp-overview-card" data-open-tab="agenda" href="#agenda"><strong><?php echo esc_html($dated); ?></strong><span>activiteiten met datum</span><small>Open de volledige jaarplanning</small></a>
        <a class="bkp-card bkp-overview-card" data-open-tab="responsibilities" href="#responsibilities"><strong><?php echo esc_html($responsibility_open_count); ?></strong><span>mijn verantwoordelijkheden</span><small>Alles wat jij moet oppakken</small></a>
        <a class="bkp-card bkp-overview-card" data-open-tab="documents" href="#documents"><strong><?php echo esc_html(count($documents)); ?></strong><span>documenten</span><small>Bestanden per project en algemeen</small></a>
    </div>

    <div class="bkp-overview-columns">
        <section class="bkp-card">
            <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Vooruitblik</span><h3>De 5 eerstvolgende activiteiten</h3></div><a data-open-tab="agenda" href="#agenda">Volledige jaarplanning</a></div>
            <?php if ($upcoming_events): ?><div class="bkp-upcoming-list">
                <?php foreach ($upcoming_events as $upcoming):
                    $date = (string) get_post_meta($upcoming->ID, '_bkp_date', true);
                    $time = trim((string) get_post_meta($upcoming->ID, '_bkp_time', true));
                    $location = trim((string) get_post_meta($upcoming->ID, '_bkp_location', true));
                    $project_name = bkp_project_name(bkp_get_project_id($upcoming->ID), 'Niet gekoppeld');
                    $details = array_filter(array($time, $location));
                ?>
                <a data-open-tab="agenda" href="#agenda"><time datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html(bkp_format_date($date)); ?></time><strong><?php echo esc_html($upcoming->post_title); ?></strong><span><?php echo esc_html($project_name . ($details ? ' · '.implode(' · ', $details) : '')); ?></span></a>
                <?php endforeach; ?>
            </div><?php else: ?><p class="bkp-muted-label">Er zijn nog geen komende activiteiten met een datum.</p><?php endif; ?>
        </section>

        <section class="bkp-card">
            <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Projectstatus</span><h3>Projecten die aandacht vragen</h3></div><a data-open-tab="projects" href="#projects">Alle projecten</a></div>
            <?php if ($projects): ?><div class="bkp-project-mini-list">
                <?php $shown = 0; foreach ($projects as $project_item): $progress = bkp_project_progress($project_item->ID); if ($shown >= 5) break; $shown++; ?>
                    <a data-open-tab="projects" href="#projects"><strong><?php echo esc_html($project_item->post_title); ?></strong><span><?php echo esc_html($progress['percent'].'% gereed · '.$progress['open'].' open'.($progress['overdue'] ? ' · '.$progress['overdue'].' te laat' : '')); ?></span></a>
                <?php endforeach; ?>
            </div><?php else: ?><p class="bkp-muted-label">Maak eerst projecten aan en koppel de planningonderdelen.</p><?php endif; ?>
        </section>
    </div>
    <?php else: ?>
    <div class="bkp-overview-grid bkp-overview-grid--guest">
        <a class="bkp-card bkp-guest-card" data-open-tab="agenda" href="#agenda">
            <span class="bkp-guest-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 3v4"/><path d="M16 3v4"/></svg></span>
            <span class="bkp-guest-body"><strong>Jaarplanning</strong><span class="bkp-guest-desc"><?php echo esc_html(count($events).' activiteiten, '.$dated.' met vastgestelde datum'); ?></span><span class="bkp-guest-go">Volledige jaarplanning<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span></span>
        </a>
        <a class="bkp-card bkp-guest-card" data-open-tab="documents" href="#documents">
            <span class="bkp-guest-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v4h4"/><path d="M9 13h6"/><path d="M9 17h6"/></svg></span>
            <span class="bkp-guest-body"><strong>Documenten</strong><span class="bkp-guest-desc"><?php echo esc_html(count($documents).' bestanden om te downloaden'); ?></span><span class="bkp-guest-go">Alle documenten<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span></span>
        </a>
        <a class="bkp-card bkp-guest-card" data-open-tab="committees" href="#committees">
            <span class="bkp-guest-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/><path d="M9 12h.01M15 12h.01M9 9h.01M15 9h.01"/></svg></span>
            <span class="bkp-guest-body"><strong>De vereniging</strong><span class="bkp-guest-desc"><?php echo esc_html('Bestuur, contact en '.count($committees).' commissie'.(count($committees) === 1 ? '' : 's')); ?></span><span class="bkp-guest-go">Bekijk de vereniging<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span></span>
        </a>
    </div>

    <div class="bkp-overview-columns">
        <section class="bkp-card">
            <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Vooruitblik</span><h3>De eerstvolgende activiteiten</h3></div><a data-open-tab="agenda" href="#agenda">Volledige jaarplanning</a></div>
            <?php if ($upcoming_events): ?><div class="bkp-upcoming-list">
                <?php foreach ($upcoming_events as $upcoming):
                    $date = (string) get_post_meta($upcoming->ID, '_bkp_date', true);
                    $time = trim((string) get_post_meta($upcoming->ID, '_bkp_time', true));
                    $location = trim((string) get_post_meta($upcoming->ID, '_bkp_location', true));
                    $project_name = bkp_project_name(bkp_get_project_id($upcoming->ID), 'Niet gekoppeld');
                    $details = array_filter(array($time, $location));
                ?>
                <a data-open-tab="agenda" href="#agenda"><time datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html(bkp_format_date($date)); ?></time><strong><?php echo esc_html($upcoming->post_title); ?></strong><span><?php echo esc_html($project_name . ($details ? ' · '.implode(' · ', $details) : '')); ?></span></a>
                <?php endforeach; ?>
            </div><?php else: ?><p class="bkp-muted-label">Er zijn nog geen komende activiteiten met een datum.</p><?php endif; ?>
        </section>

        <section class="bkp-card">
            <div class="bkp-section-heading-row"><h3>Bestuur &amp; contact</h3><a data-open-tab="info" href="#info">Alle gegevens</a></div>
            <div class="bkp-org-row">
                <span class="bkp-org-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.68 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.32 1.85.55 2.81.68A2 2 0 0 1 22 16.92Z"/></svg></span>
                <span><strong><?php echo esc_html(!empty($contact_details['contact_name']) ? $contact_details['contact_name'] : 'Bestuur'); ?></strong><span>Voor vragen of opmerkingen</span></span>
            </div>
            <div class="bkp-org-row">
                <span class="bkp-org-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                <span><strong><?php echo esc_html(count($committees).' commissie'.(count($committees) === 1 ? '' : 's').' actief'); ?></strong><span>Wie zit er waar in</span></span>
            </div>
            <div class="bkp-org-row">
                <span class="bkp-org-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
                <span><strong><?php echo esc_html('Seizoen '.get_option('bkp_season', '')); ?></strong><span>Huidige bestuursperiode</span></span>
            </div>
        </section>
    </div>

    <?php if (function_exists('bkp_registration_is_open') && bkp_registration_is_open()): ?>
    <div class="bkp-cta">
        <div>
            <h3>Werkend lid of actief in een commissie?</h3>
            <p>Meld je aan voor een persoonlijk account. Daarmee zie je je eigen taken en verantwoordelijkheden, en krijg je toegang tot het Billeplein.</p>
        </div>
        <div class="bkp-cta-actions">
            <a class="bkp-btn bkp-btn--light" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">Inloggen</a>
            <a class="bkp-btn" href="<?php echo esc_url(bkp_registration_url()); ?>">Registreren als lid</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (is_user_logged_in()) do_action('bkp_overview_extensions'); ?>
</section>

<section class="bkp-panel" data-panel="projects">
    <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Centrale kapstok</span><h2 class="bkp-section-title">Projecten</h2><p class="bkp-section-intro">Alle activiteiten, taken, inventarisaties, acties en documenten per project bij elkaar.</p></div><?php bkp_frontend_edit_button('projects'); ?></div>
    <?php if (!$projects): ?><div class="bkp-empty">Er zijn nog geen projecten aangemaakt. Een beheerder kan projecten toevoegen en bestaande hoofdonderwerpen gecontroleerd koppelen.</div>
    <?php else: ?><div class="bkp-project-grid">
        <?php foreach ($projects as $project_item):
            $progress = bkp_project_progress($project_item->ID);
            $items = $progress['items'];
            $status = bkp_project_status($project_item->ID);
            $start = (string) get_post_meta($project_item->ID, '_bkp_project_start', true);
            $end = (string) get_post_meta($project_item->ID, '_bkp_project_end', true);
            $leader = trim((string) get_post_meta($project_item->ID, '_bkp_responsible', true));
            $description = trim((string) get_post_meta($project_item->ID, '_bkp_notes', true));
            $members = bkp_linked_user_names($project_item->ID);
        ?>
        <article class="bkp-card bkp-project-card" id="project-<?php echo esc_attr($project_item->ID); ?>">
            <div class="bkp-project-card-head"><div><span class="bkp-eyebrow bkp-eyebrow--red"><?php echo esc_html(get_post_meta($project_item->ID, '_bkp_project_season', true) ?: get_option('bkp_season', '')); ?></span><h3><?php echo esc_html($project_item->post_title); ?></h3></div><span class="bkp-badge <?php echo $progress['overdue'] ? 'bkp-badge--open' : 'bkp-badge--yes'; ?>"><?php echo esc_html($status); ?></span></div>
            <?php if ($description !== ''): ?><p><?php echo nl2br(esc_html($description)); ?></p><?php endif; ?>
            <div class="bkp-project-progress" aria-label="<?php echo esc_attr($progress['percent'].' procent gereed'); ?>"><span style="width:<?php echo esc_attr($progress['percent']); ?>%"></span></div>
            <div class="bkp-project-progress-meta"><strong><?php echo esc_html($progress['percent']); ?>% gereed</strong><span><?php echo esc_html($progress['done'].' van '.$progress['total'].' werkonderdelen afgerond'); ?></span></div>
            <dl class="bkp-project-meta">
                <?php if ($leader !== ''): ?><div><dt>Projectleider</dt><dd><?php echo esc_html($leader); ?></dd></div><?php endif; ?>
                <?php if ($members): ?><div><dt>Projectleden</dt><dd><?php echo esc_html(implode(', ', $members)); ?></dd></div><?php endif; ?>
                <?php if ($start || $end): ?><div><dt>Looptijd</dt><dd><?php echo esc_html(($start ? bkp_format_date($start) : 'n.t.b.').' – '.($end ? bkp_format_date($end) : 'n.t.b.')); ?></dd></div><?php endif; ?>
                <div><dt>Open / te laat</dt><dd><?php echo esc_html($progress['open'].' open'.($progress['overdue'] ? ', '.$progress['overdue'].' te laat' : '')); ?></dd></div>
            </dl>
            <details class="bkp-project-details"><summary>Onderdelen bekijken</summary>
                <div class="bkp-project-sections">
                    <div><strong>Jaarplanning</strong><span><?php echo count($items['events']); ?></span><?php foreach (array_slice($items['events'], 0, 4) as $item): ?><small><?php echo esc_html($item->post_title); ?></small><?php endforeach; ?><a data-open-tab="agenda" href="#agenda">Open jaarplanning</a></div>
                    <div><strong>Projectplanning</strong><span><?php echo count($items['project']); ?></span><?php foreach (array_slice($items['project'], 0, 4) as $item): ?><small><?php echo esc_html($item->post_title); ?></small><?php endforeach; ?><a data-open-tab="project" href="#project">Open planning</a></div>
                    <div><strong>To-do</strong><span><?php echo count($items['todos']); ?></span><?php foreach (array_slice($items['todos'], 0, 4) as $item): ?><small><?php echo esc_html($item->post_title); ?></small><?php endforeach; ?><a data-open-tab="todo" href="#todo">Open to-do</a></div>
                    <div><strong>Inventarisaties</strong><span><?php echo count($items['inventories']); ?></span><?php foreach (array_slice($items['inventories'], 0, 4) as $item): ?><small><?php echo esc_html($item->post_title); ?></small><?php endforeach; ?><a data-open-tab="inventory" href="#inventory">Open inventarisaties</a></div>
                    <div><strong>Acties &amp; besluiten</strong><span><?php echo count($items['actions']); ?></span><?php foreach (array_slice($items['actions'], 0, 4) as $item): ?><small><?php echo esc_html($item->post_title); ?></small><?php endforeach; ?><a data-open-tab="actions" href="#actions">Open acties</a></div>
                    <div><strong>Documenten</strong><span><?php echo count($items['documents']); ?></span><?php foreach (array_slice($items['documents'], 0, 4) as $item): ?><small><?php echo esc_html($item['title']); ?></small><?php endforeach; ?><a data-open-tab="documents" href="#documents">Open documenten</a></div>
                </div>
            </details>
        </article>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>

<section class="bkp-panel" data-panel="responsibilities">
    <div class="bkp-section-heading-row"><div><span class="bkp-eyebrow bkp-eyebrow--red">Jouw persoonlijke overzicht</span><h2 class="bkp-section-title">Mijn verantwoordelijkheden</h2><p class="bkp-section-intro">Alles wat jij moet oppakken, per project bij elkaar. Filter snel op project, onderdeel of termijn.</p></div></div>
    <?php if (!is_user_logged_in()): ?><div class="bkp-empty"><p>Log in met je persoonlijke WordPress-account om te zien welke projecten, activiteiten, taken en acties aan jou gekoppeld zijn.</p><p><a class="bkp-btn" href="<?php echo esc_url(wp_login_url(home_url('/#responsibilities'))); ?>">Inloggen en mijn verantwoordelijkheden bekijken</a></p></div>
    <?php elseif ($responsibility_total_count === 0): ?><div class="bkp-empty">Er zijn momenteel geen verantwoordelijkheden aan jouw account gekoppeld.</div>
    <?php else: ?>
        <div class="bkp-responsibility-filters" aria-label="Filters voor mijn verantwoordelijkheden">
            <label class="bkp-responsibility-filter-search"><span>Zoeken</span><input class="bkp-field" id="bkp-responsibility-search" type="search" placeholder="Zoek taak, activiteit of status"></label>
            <label><span>Project</span><select class="bkp-field" id="bkp-responsibility-project-filter"><option value="">Alle projecten</option><?php foreach ($responsibility_filter_projects as $project_id => $project_label): ?><option value="<?php echo esc_attr((string)$project_id); ?>"><?php echo esc_html($project_label); ?></option><?php endforeach; ?><?php if ($responsibility_has_unlinked): ?><option value="0">Niet gekoppeld</option><?php endif; ?></select></label>
            <label><span>Onderdeel</span><select class="bkp-field" id="bkp-responsibility-section-filter"><option value="">Alle onderdelen</option><?php foreach ($responsibility_filter_sections as $section_key => $section_label): ?><option value="<?php echo esc_attr($section_key); ?>"><?php echo esc_html($section_label); ?></option><?php endforeach; ?></select></label>
            <label><span>Termijn</span><select class="bkp-field" id="bkp-responsibility-bucket-filter"><option value="">Alle termijnen</option><option value="overdue">Te laat</option><option value="soon">Binnen 14 dagen</option><option value="later">Later</option><option value="undated">Zonder deadline</option><option value="completed">Afgerond / verstreken</option></select></label>
        </div>
        <div class="bkp-responsibility-filter-summary"><span id="bkp-responsibility-filter-results" aria-live="polite"></span><button class="bkp-filter-reset" id="bkp-responsibility-filter-reset" type="button" hidden>Filters wissen</button></div>
        <div class="bkp-empty" id="bkp-responsibility-no-results" hidden>Geen verantwoordelijkheden gevonden met deze filters.</div>
        <?php if ($responsibility_open_count === 0): ?><div class="bkp-empty bkp-responsibility-open-empty">Er zijn momenteel geen open verantwoordelijkheden aan jouw account gekoppeld.</div><?php else: ?>
            <?php bkp_render_responsibility_group('Te laat', $responsibility_groups['overdue'], 'is-overdue'); ?>
            <?php bkp_render_responsibility_group('Binnen 14 dagen', $responsibility_groups['soon'], 'is-soon'); ?>
            <?php bkp_render_responsibility_group('Later', $responsibility_groups['later']); ?>
            <?php bkp_render_responsibility_group('Zonder deadline', $responsibility_groups['undated']); ?>
        <?php endif; ?>
        <?php if (!empty($responsibility_groups['completed'])): ?><details class="bkp-completed-responsibilities" data-bkp-completed-wrapper><summary>Afgerond of verstreken (<?php echo esc_html(count($responsibility_groups['completed'])); ?>)</summary><?php bkp_render_responsibility_group('Afgerond of verstreken', $responsibility_groups['completed'], 'is-completed'); ?></details><?php endif; ?>
    <?php endif; ?>
</section>

<section class="bkp-panel" data-panel="agenda">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Jaarplanning</h2><?php bkp_frontend_edit_button('events'); ?></div>
    <div class="bkp-toolbar">
        <label><span class="screen-reader-text">Zoeken</span><input class="bkp-field bkp-search" id="bkp-search" type="search" placeholder="Zoek activiteit, project, locatie of persoon"></label>
        <label><span class="screen-reader-text">Maand</span><select class="bkp-field" id="bkp-month-filter"><option value="">Alle maanden</option><?php $month_options=array(); foreach($events as $event){$date=get_post_meta($event->ID,'_bkp_date',true);$key=$date?substr($date,0,7):'undated';$month_options[$key]=$date?ucfirst(bkp_dutch_month($date)):'Zonder exacte datum';} foreach($month_options as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
        <label><span class="screen-reader-text">Status</span><select class="bkp-field" id="bkp-status-filter"><option value="">Alle statussen</option><option value="yes">Vastgesteld</option><option value="no">Concept</option></select></label>
    </div>
    <div class="bkp-filter-summary"><span class="bkp-filter-results" id="bkp-filter-results" aria-live="polite"></span><button class="bkp-filter-reset" id="bkp-filter-reset" type="button" hidden>Filters wissen</button></div>
    <?php $current=''; foreach($events as $event):
        $date=get_post_meta($event->ID,'_bkp_date',true); $label=get_post_meta($event->ID,'_bkp_date_label',true); $month=$date?substr($date,0,7):'undated'; $monthlabel=$date?bkp_dutch_month($date):'Zonder exacte datum';
        if($month!==$current): if($current!==''): ?></div><?php endif; ?><h2 class="bkp-month" data-month-heading="<?php echo esc_attr($month); ?>"><?php echo esc_html(ucfirst($monthlabel)); ?></h2><div class="bkp-events" data-month-group="<?php echo esc_attr($month); ?>"><?php $current=$month; endif;
        list($day,$mon)=bkp_date_parts($date,$label); $time=get_post_meta($event->ID,'_bkp_time',true); $loc=get_post_meta($event->ID,'_bkp_location',true); $who=get_post_meta($event->ID,'_bkp_responsible',true); $attire=get_post_meta($event->ID,'_bkp_attire',true); $notes=get_post_meta($event->ID,'_bkp_notes',true); $yes=get_post_meta($event->ID,'_bkp_confirmed',true)==='1'; $project_name=bkp_project_name(bkp_get_project_id($event->ID),'Niet gekoppeld');
        $search_source=$event->post_title.' '.$project_name.' '.$label.' '.$time.' '.$loc.' '.$who.' '.$attire.' '.$notes; $search=function_exists('mb_strtolower')?mb_strtolower($search_source,'UTF-8'):strtolower($search_source);
    ?>
    <article class="bkp-event" data-event data-search="<?php echo esc_attr($search); ?>" data-month="<?php echo esc_attr($month); ?>" data-status="<?php echo $yes?'yes':'no'; ?>"><div class="bkp-date"><b><?php echo esc_html($day); ?></b><span><?php echo esc_html($mon); ?></span></div><div><div class="bkp-event-project"><?php echo esc_html($project_name); ?></div><h3><?php echo esc_html($event->post_title); ?></h3><div class="bkp-meta"><span><?php echo $time?'Tijd: '.esc_html($time):''; ?></span><span><?php echo $loc?'Locatie: '.esc_html($loc):''; ?></span><span><?php echo $who?'Wie: '.esc_html($who):''; ?></span><span><?php echo $attire?'Ornaat: '.esc_html($attire):''; ?></span><span><?php echo $notes?'Notitie: '.esc_html($notes):''; ?></span></div></div><span class="bkp-badge <?php echo $yes?'bkp-badge--yes':''; ?>"><?php echo $yes?'Vastgesteld':'Concept'; ?></span></article>
    <?php endforeach; if($current!==''): ?></div><?php endif; ?><div class="bkp-empty" id="bkp-no-results" hidden>Geen activiteiten gevonden met deze filters.</div>
</section>

<section class="bkp-panel" data-panel="project">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Projectplanning</h2><?php bkp_frontend_edit_button('project'); ?></div>
    <p class="bkp-section-intro">De rode balken tonen in welke maanden een taak actief is. Iedere regel kan aan een centraal project worden gekoppeld.</p>
    <div class="bkp-table-wrap"><table class="bkp-table bkp-gantt"><thead><tr><th>Project</th><th>Taak</th><th>Verantwoordelijke</th><?php foreach($months as $month): ?><th class="bkp-month-cell"><span class="bkp-month-head"><?php echo esc_html(($month['label']??'').' '.($month['year']??'')); ?></span></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach($project as $task): $active=array_filter(explode(',',(string)get_post_meta($task->ID,'_bkp_months',true))); ?>
        <tr><td><span class="bkp-topic-label"><?php echo esc_html(bkp_project_name(bkp_get_project_id($task->ID),'Niet gekoppeld')); ?></span></td><td class="bkp-task-name"><?php echo esc_html($task->post_title); ?><br><small><?php echo esc_html(get_post_meta($task->ID,'_bkp_period',true)); ?><?php $task_status=get_post_meta($task->ID,'_bkp_task_status',true); $task_notes=get_post_meta($task->ID,'_bkp_notes',true); echo $task_status?' · '.esc_html($task_status):''; ?></small><?php if($task_notes): ?><br><small><?php echo esc_html($task_notes); ?></small><?php endif; ?></td><td class="bkp-owner"><?php echo esc_html(get_post_meta($task->ID,'_bkp_responsible',true)?:'Nog niet ingevuld'); ?></td><?php foreach($months as $month):$key=$month['key']??''; ?><td class="bkp-month-cell <?php echo in_array($key,$active,true)?'is-active':''; ?>" title="<?php echo esc_attr($month['label']??''); ?>"><span></span></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="bkp-panel" data-panel="inventory">
    <div class="bkp-section-heading-row"><div><h2 class="bkp-section-title">Inventarisaties</h2><p class="bkp-section-intro">Vul per inventarisatie alleen je naam en het aantal in. Inventarisaties kunnen per project worden georganiseerd.</p></div><?php bkp_frontend_edit_button('inventories'); ?></div>
    <?php bkp_render_inventory_frontend($inventories); ?>
</section>

<section class="bkp-panel" data-panel="todo">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">To-do</h2><?php bkp_frontend_edit_button('todos'); ?></div>
    <p class="bkp-section-intro">Werkpunten gekoppeld aan projecten zoals Carnaval, Pronkzitting of Ludieke stunt.</p>
    <?php if($todos): ?><div class="bkp-topic-filter"><label for="bkp-todo-topic-filter"><span>Project</span><select class="bkp-field" id="bkp-todo-topic-filter"><option value="">Alle projecten</option><?php foreach($project_filter_options as $project_id=>$project_name): ?><option value="<?php echo esc_attr($project_id); ?>"><?php echo esc_html($project_name); ?></option><?php endforeach; ?><?php if($has_unlinked_todos): ?><option value="__empty__">Niet gekoppeld</option><?php endif; ?></select></label><span id="bkp-todo-topic-results" class="bkp-filter-results"><?php echo esc_html(count($todos).' werkpunten'); ?></span></div><?php endif; ?>
    <div class="bkp-table-wrap"><table class="bkp-table bkp-todo-table"><thead><tr><th>Project</th><th>Onderwerp</th><th>Wie</th><th>Periode</th><th>Deadline</th><th>Status</th><th>Notities</th></tr></thead><tbody>
        <?php foreach($todos as $task): $status=get_post_meta($task->ID,'_bkp_task_status',true); $deadline=get_post_meta($task->ID,'_bkp_due_date',true); $project_id=bkp_get_project_id($task->ID); $project_name=bkp_project_name($project_id,'Niet gekoppeld'); ?>
        <tr data-todo-row data-topic="<?php echo esc_attr($project_id ?: ''); ?>"><td><span class="bkp-topic-label"><?php echo esc_html($project_name); ?></span></td><td><strong><?php echo esc_html($task->post_title); ?></strong></td><td><?php echo esc_html(get_post_meta($task->ID,'_bkp_responsible',true)?:'Nog niet ingevuld'); ?></td><td><?php echo esc_html(get_post_meta($task->ID,'_bkp_period',true)); ?></td><td><?php echo esc_html($deadline?bkp_format_date($deadline):''); ?></td><td><span class="bkp-badge <?php echo bkp_is_done($status)?'bkp-badge--yes':'bkp-badge--open'; ?>"><?php echo esc_html($status?:'open'); ?></span></td><td><?php echo esc_html(get_post_meta($task->ID,'_bkp_notes',true)); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div><div id="bkp-todo-no-results" class="bkp-empty" hidden>Er zijn geen werkpunten voor dit project.</div>
</section>

<section class="bkp-panel" data-panel="actions">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Acties en besluiten</h2><?php bkp_frontend_edit_button('actions'); ?></div>
    <p class="bkp-section-intro">Overzicht van vastgelegde acties en besluiten per project.</p>
    <div class="bkp-table-wrap"><table class="bkp-table"><thead><tr><th>Project</th><th>A/B</th><th>Datum</th><th>Actie</th><th>Wie</th><th>Einddatum</th><th>Af?</th><th>Notities</th></tr></thead><tbody>
        <?php foreach($actions as $action): $done=get_post_meta($action->ID,'_bkp_done',true); ?>
        <tr><td><span class="bkp-topic-label"><?php echo esc_html(bkp_project_name(bkp_get_project_id($action->ID),'Niet gekoppeld')); ?></span></td><td><?php echo esc_html(get_post_meta($action->ID,'_bkp_action_kind',true)); ?></td><td><?php echo esc_html(bkp_format_date(get_post_meta($action->ID,'_bkp_date',true))); ?></td><td><strong><?php echo esc_html($action->post_title); ?></strong></td><td><?php echo esc_html(get_post_meta($action->ID,'_bkp_responsible',true)?:'Niet ingevuld'); ?></td><td><?php echo esc_html(bkp_format_date(get_post_meta($action->ID,'_bkp_due_date',true))); ?></td><td><span class="bkp-badge <?php echo bkp_is_done($done)?'bkp-badge--yes':'bkp-badge--open'; ?>"><?php echo esc_html($done?:'Nee'); ?></span></td><td><?php echo esc_html(get_post_meta($action->ID,'_bkp_notes',true)); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="bkp-panel" data-panel="committees">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Commissies</h2><?php bkp_frontend_edit_button('committees'); ?></div><p class="bkp-section-intro">Overzicht van de personen die bij iedere commissie horen.</p>
    <?php if($committees): ?><div class="bkp-committee-grid"><?php foreach($committees as $committee_name=>$members): ?><article class="bkp-card bkp-committee-card"><h3><?php echo esc_html($committee_name); ?></h3><ul class="bkp-committee-name-list"><?php foreach($members as $member): ?><li><?php echo esc_html($member['name']); ?></li><?php endforeach; ?></ul></article><?php endforeach; ?></div><?php else: ?><div class="bkp-empty">Er zijn nog geen commissies ingevuld.</div><?php endif; ?>
</section>

<section class="bkp-panel" id="documents" data-panel="documents">
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Documenten</h2><?php bkp_frontend_edit_button('documents'); ?></div><p class="bkp-section-intro">Download algemene documenten en bestanden die aan een project zijn gekoppeld.</p>
    <?php if($documents): ?><div class="bkp-document-grid"><?php foreach($documents as $document): ?><article class="bkp-card bkp-document-card"><?php if(!empty($document['project_id'])): ?><span class="bkp-topic-label"><?php echo esc_html(bkp_project_name($document['project_id'],'')); ?></span><?php endif; ?><h3><?php echo esc_html($document['title']); ?></h3><?php if(!empty($document['description'])): ?><p><?php echo nl2br(esc_html($document['description'])); ?></p><?php endif; ?><span class="bkp-document-meta"><?php echo esc_html($document['original_name']); ?><?php if(!empty($document['size'])): ?> · <?php echo esc_html(size_format((int)$document['size'])); ?><?php endif; ?></span><a class="bkp-btn" href="<?php echo esc_url(bkp_document_download_url($document)); ?>">Download document</a></article><?php endforeach; ?></div><?php else: ?><div class="bkp-empty">Er zijn nog geen documenten beschikbaar gesteld.</div><?php endif; ?>
</section>

<section class="bkp-panel" data-panel="info">
    <?php $contact_details=(array)get_option('bkp_contact_details',array()); ?>
    <div class="bkp-section-heading-row"><h2 class="bkp-section-title">Contact en rollen</h2><?php bkp_frontend_edit_button('board'); ?></div>
    <div class="bkp-info-grid"><article class="bkp-card"><h3>Contact bij vragen of opmerkingen</h3><?php if(!empty($contact_details['contact_name'])): ?><p><strong><?php echo esc_html($contact_details['contact_name']); ?></strong></p><?php endif; ?><p class="bkp-contact"><?php echo esc_html(!empty($contact_details['notes'])?$contact_details['notes']:($contact?:'Nog niet ingevuld')); ?></p><?php if(!empty($contact_details['phone'])): ?><p><strong>Telefoon:</strong> <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','',$contact_details['phone'])); ?>"><?php echo esc_html($contact_details['phone']); ?></a></p><?php endif; ?><?php if(!empty($contact_details['email'])): ?><p><strong>E-mail:</strong> <a href="mailto:<?php echo esc_attr($contact_details['email']); ?>"><?php echo esc_html($contact_details['email']); ?></a></p><?php endif; ?><?php if(!empty($contact_details['address'])): ?><p class="bkp-contact"><strong>Adres:</strong><br><?php echo esc_html($contact_details['address']); ?></p><?php endif; ?><?php if(!empty($contact_details['website'])): ?><p><strong>Website:</strong> <a href="<?php echo esc_url($contact_details['website']); ?>" rel="noopener"><?php echo esc_html($contact_details['website']); ?></a></p><?php endif; ?><p><strong>Vastgesteld in jaarplanning:</strong> <?php echo esc_html($confirmed); ?> activiteit(en)</p></article>
    <div class="bkp-table-wrap"><table class="bkp-table"><thead><tr><th>Rol</th><th>Naam / namen</th><th>Telefoon</th><th>E-mail</th><th>Notities</th></tr></thead><tbody><?php foreach($board as $row): ?><tr><td><strong><?php echo esc_html($row['role']??''); ?></strong></td><td><?php echo esc_html($row['name']??($row['people']??'Nog niet ingevuld')); ?></td><td><?php if(!empty($row['phone'])): ?><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','',$row['phone'])); ?>"><?php echo esc_html($row['phone']); ?></a><?php endif; ?></td><td><?php if(!empty($row['email'])): ?><a href="mailto:<?php echo esc_attr($row['email']); ?>"><?php echo esc_html($row['email']); ?></a><?php endif; ?></td><td><?php echo esc_html($row['notes']??''); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</section>
<?php if (is_user_logged_in()) do_action('bkp_extra_panels'); ?>
</div></main>
