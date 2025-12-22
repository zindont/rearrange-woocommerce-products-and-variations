<?php

/**
 * Plugin Name: Rearrange Products for WooCommerce and Variations
 * Description: Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product"
 * Author:            An Ho
 * Author URI:        https://www.linkedin.com/in/andeptrai/
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Common: Apply WVASP exclude rule
 * - Exclude posts where _wvasp_exclude = 'yes'
 */
function rwppv_apply_wvasp_exclude_rule(WP_Query $q): void
{
    $meta_query = (array) $q->get('meta_query', []);

    // Avoid duplicates if called multiple times
    foreach ($meta_query as $clause) {
        if (is_array($clause) && isset($clause['relation']) && $clause['relation'] === 'OR') {
            // best-effort: don't over-engineer; harmless if duplicated
        }
    }

    $meta_query[] = [
        'relation' => 'OR',
        [
            'key'     => '_wvasp_exclude',
            'compare' => 'NOT EXISTS',
        ],
        [
            'key'     => '_wvasp_exclude',
            'value'   => 'yes',
            'compare' => '!=',
        ],
    ];

    $q->set('meta_query', $meta_query);
}

/**
 * Helper: Identify RWPP v5 list query signature
 * RWPP list queries (page + ajax) build:
 * - post_type   = ['product']
 * - post_status = ['publish']
 */
function rwppv_is_rwpp_list_query(WP_Query $q): bool
{
    $pt     = $q->get('post_type');
    $status = $q->get('post_status');

    return is_array($pt) && $pt === ['product']
        && is_array($status) && $status === ['publish'];
}

/**
 * ------------------------------------------------------------------
 * 1) ADMIN: RWPP page – include product_variation in main list query
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if (! is_admin()) {
        return;
    }

    // RWPP admin page is usually admin.php?page=rwpp-page
    if (empty($_GET['page']) || $_GET['page'] !== 'rwpp-page') {
        return;
    }

    if (! rwppv_is_rwpp_list_query($q)) {
        return;
    }

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclude logic
    rwppv_apply_wvasp_exclude_rule($q);
}, 20);

/**
 * ------------------------------------------------------------------
 * 2) ADMIN AJAX: RWPP "Load more products" – include variations too
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if (! (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    // RWPP load more action
    if (empty($_REQUEST['action']) || $_REQUEST['action'] !== 'load_more_products') {
        return;
    }

    if (! rwppv_is_rwpp_list_query($q)) {
        return;
    }

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclude logic
    rwppv_apply_wvasp_exclude_rule($q);
}, 20);

/**
 * ------------------------------------------------------------------
 * 3) FRONTEND: join RWPP global order table for queries including variations
 * ------------------------------------------------------------------
 */
add_filter('posts_join', function ($join, WP_Query $q) {

    if (is_admin()) {
        return $join;
    }

    $pt = $q->get('post_type');

    // Only affect queries that already include variations (i.e. "variations as products" plugin)
    if (! (is_array($pt) && in_array('product_variation', $pt, true))) {
        return $join;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rwpp_product_order';

    // Join once
    if (strpos($join, $table) === false) {
        $join .= " LEFT JOIN {$table} AS rwpp_order
                   ON {$wpdb->posts}.ID = rwpp_order.product_id
                   AND rwpp_order.category_id = 0";
    }

    return $join;
}, 20, 2);

/**
 * ------------------------------------------------------------------
 * 4) FRONTEND: order by RWPP global order (category_id = 0)
 * ------------------------------------------------------------------
 */
add_filter('posts_orderby', function ($orderby, WP_Query $q) {

    if (is_admin()) {
        return $orderby;
    }

    $pt = $q->get('post_type');

    // Only affect queries that already include variations
    if (! (is_array($pt) && in_array('product_variation', $pt, true))) {
        return $orderby;
    }

    global $wpdb;

    return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
}, 20, 2);
