<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Publieke, read-only koppeling voor de openbare verenigingswebsite.
 * Alleen activiteiten die een beheerder expliciet als openbaar markeert
 * worden aangeboden. Interne notities, gebruikers, e-mailadressen en
 * verantwoordelijkheden verlaten de planner nooit via dit endpoint.
 */
function bkp_public_api_register_routes() {
    register_rest_route('billekletsers/v1', '/activiteiten', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'bkp_public_api_events',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('billekletsers/v1', '/status', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'bkp_public_api_status',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'bkp_public_api_register_routes');

function bkp_public_api_status() {
    return rest_ensure_response(array(
        'ok' => true,
        'season' => sanitize_text_field((string) get_option('bkp_season', '')),
        'version' => defined('BKP_CORE_VERSION') ? BKP_CORE_VERSION : '',
    ));
}

function bkp_public_api_events(WP_REST_Request $request) {
    $posts = get_posts(array(
        'post_type' => 'bkp_event',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => array(
            array('key' => '_bkp_public_web', 'value' => '1', 'compare' => '='),
        ),
        'suppress_filters' => false,
    ));

    $items = array();
    $latest_modified = '';
    foreach ($posts as $post) {
        $date = trim((string) get_post_meta($post->ID, '_bkp_date', true));
        $label = trim((string) get_post_meta($post->ID, '_bkp_date_label', true));
        $time = trim((string) get_post_meta($post->ID, '_bkp_time', true));
        $location = trim((string) get_post_meta($post->ID, '_bkp_location', true));
        $public_text = trim((string) get_post_meta($post->ID, '_bkp_public_text', true));
        $confirmed = get_post_meta($post->ID, '_bkp_confirmed', true) === '1';
        $items[] = array(
            'id' => (int) $post->ID,
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
            'date_label' => $label,
            'time' => $time,
            'location' => $location,
            'description' => $public_text,
            'confirmed' => $confirmed,
            'modified_gmt' => get_post_modified_time('c', true, $post),
        );
        $modified = get_post_modified_time('Y-m-d H:i:s', true, $post);
        if ($modified && ($latest_modified === '' || $modified > $latest_modified)) $latest_modified = $modified;
    }

    usort($items, function($a, $b) {
        $ad = $a['date'] ?: '9999-12-31';
        $bd = $b['date'] ?: '9999-12-31';
        if ($ad === $bd) return strnatcasecmp($a['title'], $b['title']);
        return strcmp($ad, $bd);
    });

    $response = rest_ensure_response(array(
        'season' => sanitize_text_field((string) get_option('bkp_season', '')),
        'generated_at' => current_time('c', true),
        'latest_modified_gmt' => $latest_modified,
        'count' => count($items),
        'items' => $items,
    ));
    $response->header('Cache-Control', 'public, max-age=120, must-revalidate');
    return $response;
}
