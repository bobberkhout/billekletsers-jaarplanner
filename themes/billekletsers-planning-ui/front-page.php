<?php
get_header();
if (function_exists('bkp_render_planner_app')) {
    bkp_render_planner_app();
} else {
    echo '<main class="bkp-main"><div class="bkp-wrap"><section class="bkp-card"><h1>Jaarplanner-plugin ontbreekt</h1><p>Activeer de plugin <strong>Billekletsers Jaarplanner</strong>. De bestaande gegevens zijn niet verwijderd; alleen de functionaliteit is nog niet geladen.</p></section></div></main>';
}
get_footer();
