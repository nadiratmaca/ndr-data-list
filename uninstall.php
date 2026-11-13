<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$posts = get_posts(array(
    'post_type' => 'ndr_api_source',
    'posts_per_page' => -1,
    'post_status' => 'any',
    'fields' => 'ids',
));

foreach ($posts as $post_id) {
    wp_delete_post($post_id, true);
}
