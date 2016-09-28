<?php
/*
Plugin Name: CloudFlare Full Page Caching
Description: Required for CloudFlare page-cached sites to function properly. Requires "CloudFlare" plugin. Borrows heavily from Varnish HTTP Purge.
Version: 1.0
Author: Michael Shadle <mike503@gmail.com>
Author URI: https://michaelshadle.com
*/

// CLEAN CROSS-SITE "MIXED ROLE" ISSUES - don't allow "logged in" options to display to anonymous users
add_filter('show_admin_bar', '__return_false');
add_filter('edit_post_link', '__return_null');

// FULL-SITE EVENT PURGES
add_action('autoptimize_action_cachepurged', '_cloudflare_full_page_caching_purge_zone');
add_action('switch_theme', '_cloudflare_full_page_caching_zone');
add_action('admin_bar_menu', '_cloudflare_full_page_caching_adminbar', 100);
if (isset($_GET['cloudflare_full_page_caching_purge_zone'])) {
  add_action('admin_notices' , '_cloudflare_full_page_caching_purge_zone');
}

function _cloudflare_full_page_caching_adminbar($admin_bar) {
  $admin_bar->add_menu(array(
    'id' => 'cloudflare-full-page-caching-purge-zone',
    'title' => __('Purge CloudFlare', 'cloudflare-full-page-caching-purge-zone'),
    'href' => wp_nonce_url(add_query_arg('cloudflare_full_page_caching_purge_zone', 1), 'cloudflare-full-page-caching-purge-zone'),
    'meta' => array(
      'title' => __('Purge CloudFlare', 'cloudflare-full-page-caching-purge-zone'),
    ),
  ));
}

function _cloudflare_full_page_caching_purge_zone() {
  if (!$zone_id = _cloudflare_full_page_caching_zone_id()) {
// could add message
    return FALSE;
  }
  $result = _cloudflare_full_page_caching_request('DELETE', 'zones/' . $zone_id . '/purge_cache', array('purge_everything' => TRUE));
  if (!isset($result['success']) || intval($result['success']) != 1) {
    echo "<div id='message' class='error fade'><p><strong>".__('CloudFlare cache purge request FAILED. Try again in a couple minutes. Message: ' . $result['msg'], 'cloudflare-full-page-caching')."</strong></p></div>";
  }
  else {
    echo "<div id='message' class='updated fade'><p><strong>".__('CloudFlare cache purge request sent - may take a minute.', 'cloudflare-full-page-caching')."</strong></p></div>";
  }
}

// PER-PAGE EVENT PURGING
add_action('wp_scheduled_delete', '_cloudflare_full_page_caching_purge_urls');
add_action('transition_post_status', '_cloudflare_full_page_caching_purge_urls', 10, 3);
/* look at leveraging these - first param is the post ID on some of these
add_action('save_post', '_cloudflare_full_page_caching_purge_urls');
add_action('deleted_post', '_cloudflare_full_page_caching_purge_urls');
add_action('trashed_post', '_cloudflare_full_page_caching_purge_urls');
add_action('edit_post', '_cloudflare_full_page_caching_purge_urls');
add_action('delete_attachment', '_cloudflare_full_page_caching_purge_urls');
*/

function _cloudflare_full_page_caching_purge_urls($a, $b, $post) {
  if (!$zone_id = _cloudflare_full_page_caching_zone_id()) {
// could add message
    return FALSE;
  }
  // borrowed heavily from varnish-http-purge
  $urls = array();
  if ($categories = get_the_category($post->ID)) {
    foreach ($categories as $cat) {
      array_push($urls, get_category_link($cat->term_id));
    }
  }
  if ($tags = get_the_tags($post->ID)) {
    foreach ($tags as $tag) {
      array_push($urls, get_tag_link( $tag->term_id));
    }
  }
  array_push($urls,
    get_author_posts_url(get_post_field('post_author', $post->ID)),
    get_author_feed_link(get_post_field('post_author', $post->ID))
  );
  if (get_post_type_archive_link(get_post_type($post->ID)) == true) {
    array_push($urls,
      get_post_type_archive_link(get_post_type($post->ID)),
      get_post_type_archive_feed_link(get_post_type($post->ID))
    );
  }
  array_push($urls, get_permalink($post->ID));
  array_push($urls,
    get_bloginfo_rss('rdf_url') ,
    get_bloginfo_rss('rss_url') ,
    get_bloginfo_rss('rss2_url'),
    get_bloginfo_rss('atom_url'),
    get_bloginfo_rss('comments_rss2_url'),
    get_post_comments_feed_link($post->ID)
  );
  array_push($urls, home_url('/'));
  if (get_option('show_on_front') == 'page') {
    array_push($urls, get_permalink(get_option('page_for_posts')));
  }
  $result = _cloudflare_full_page_caching_request('DELETE', 'zones/' . $zone_id . '/purge_cache', array('files' => $urls));
  if (isset($result['success']) || intval($result['success']) == 1) {
    return TRUE;
  }
  return FALSE;
}

function _cloudflare_full_page_caching_zone_id() {
  if ($zone_id = get_option('_cloudflare_full_page_caching_zone_id')) {
    return $zone_id;
  }
  if (!$result = _cloudflare_full_page_caching_request('GET', 'zones', array('name' => get_option('cloudflare_zone_name')))) {
    trigger_error("Could not get CloudFlare zone ID");
    return FALSE;
  }
  $zone_id = isset($result['result'][0]['id']) ? $result['result'][0]['id'] : FALSE;
  if ($zone_id) {
    add_option('_cloudflare_full_page_caching_zone_id', $zone_id);
    return $zone_id;
  }
  trigger_error("Could not get CloudFlare zone ID");
  return FALSE;
}

function _cloudflare_full_page_caching_request($method ='', $path = '', $params = array()) {
  $headers = array(
    'X-Auth-Email: ' . get_option('cloudflare_api_email'),
    'X-Auth-Key: ' . get_option('cloudflare_api_key'),
    'Content-Type: application/json',
  );
  $ch = curl_init();
  if ($method == 'POST' || $method == 'PUT' || $method == 'DELETE') {
    $params_string = json_encode($params);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
    $headers[] = 'Content-Length: ' . strlen($params_string);
  }
  elseif ($method == 'GET') {
    if (!empty($params)) {
      $path .= '?' . http_build_query($params);
    }
  }
  curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/' . $path);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $result = curl_exec($ch);
  curl_close($ch);
  return json_decode($result, TRUE);
}
