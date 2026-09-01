<?php
if (!defined('ABSPATH')) { exit; }

define('BKP_UI_THEME_VERSION', '4.0.0');

function bkp_ui_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('search-form','gallery','caption','style','script'));
}
add_action('after_setup_theme', 'bkp_ui_theme_setup');

function bkp_ui_theme_assets() {
    wp_enqueue_style('bkp-ui-theme', get_stylesheet_uri(), array(), BKP_UI_THEME_VERSION);
}
add_action('wp_enqueue_scripts', 'bkp_ui_theme_assets');

function bkp_ui_plugin_notice() {
    if (!current_user_can('manage_options') || function_exists('bkp_render_planner_app')) return;
    echo '<div class="notice notice-error"><p><strong>De Billekletsers Jaarplanner-plugin is niet actief.</strong> Activeer de plugin om de planning en bestaande gegevens te tonen.</p></div>';
}
add_action('admin_notices', 'bkp_ui_plugin_notice');
