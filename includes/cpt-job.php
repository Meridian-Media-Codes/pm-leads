<?php
if ( ! defined('ABSPATH') ) exit;

function pm_leads_register_cpt_job() {

    $labels = [
        'name'               => __('Jobs', 'pm-leads'),
        'singular_name'      => __('Job', 'pm-leads'),
        'add_new'            => __('Add New', 'pm-leads'),
        'add_new_item'       => __('Add New Job', 'pm-leads'),
        'edit_item'          => __('Edit Job', 'pm-leads'),
        'new_item'           => __('New Job', 'pm-leads'),
        'view_item'          => __('View Job', 'pm-leads'),
        'search_items'       => __('Search Jobs', 'pm-leads'),
        'not_found'          => __('No jobs found', 'pm-leads'),
        'not_found_in_trash' => __('No jobs found in Trash', 'pm-leads'),
        'menu_name'          => __('Jobs', 'pm-leads'),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,              // ✅ NOW visible in menu
        'menu_icon'          => 'dashicons-clipboard',
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'hierarchical'       => false,
        'supports'           => ['title', 'custom-fields'],
        'has_archive'        => false,
        'rewrite'            => false,
    ];

    register_post_type('pm_job', $args);

    // Job Status taxonomy
    $tx_labels = [
        'name'          => __('Job Statuses', 'pm-leads'),
        'singular_name' => __('Job Status', 'pm-leads'),
    ];

    register_taxonomy('pm_job_status', ['pm_job'], [
        'labels'       => $tx_labels,
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'hierarchical' => false,
    ]);
}
add_action('init', 'pm_leads_register_cpt_job');

/*
 * ✅ Make title clickable (opens post editor)
 */
add_filter('post_type_link', function ($link, $post) {
    if ($post->post_type === 'pm_job') {
        return admin_url('post.php?post=' . $post->ID . '&action=edit');
    }
    return $link;
}, 10, 2);

/*
 * ✅ Ensure the title column is kept
 */
add_filter('manage_pm_job_posts_columns', function ($columns) {
    if (!isset($columns['title'])) {
        $columns = ['title' => __('Job', 'pm-leads')] + $columns;
    }
    return $columns;
});
