<?php

/**
 * Plugin Name: Rearrange WooCommerce Products and Variations
 * Description: Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product"
 * Author:            An Ho
 * Author URI:        https://www.linkedin.com/in/andeptrai/
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * 1. ADMIN: RWPP page – add product_variation into list
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if (! is_admin()) {
        return;
    }

    if (empty($_GET['page']) || $_GET['page'] !== 'rwpp-page') {
        return;
    }

    $pt     = $q->get('post_type');
    $status = $q->get('post_status');

    /**
     * RWPP v5 list query signature:
     * post_type   = ['product']
     * post_status = ['publish']
     */
    $is_rwpp_list_query =
        is_array($pt) &&
        $pt === ['product'] &&
        is_array($status) &&
        $status === ['publish'];

    if (! $is_rwpp_list_query) {
        return;
    }

    /**
     * 1. Include variations
     */
    $q->set('post_type', ['product', 'product_variation']);

    /**
     * 2. Apply same exclude rule as "Variations as Single Product"
     *    (_wvasp_exclude = yes)
     */
    $meta_query = (array) $q->get('meta_query', []);

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
}, 20);


/**
 * ------------------------------------------------------------------
 * 2. FRONTEND: JOIN RWPP global order table
 * ------------------------------------------------------------------
 */
add_filter('posts_join', function ($join, WP_Query $q) {

    if (is_admin()) {
        return $join;
    }

    $pt = $q->get('post_type');

    $is_shop_like_query =
        is_array($pt) &&
        in_array('product_variation', $pt, true);

    if (! $is_shop_like_query) {
        return $join;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rwpp_product_order';

    if (strpos($join, $table) === false) {
        $join .= " LEFT JOIN {$table} AS rwpp_order
                   ON {$wpdb->posts}.ID = rwpp_order.product_id
                   AND rwpp_order.category_id = 0";
    }

    return $join;
}, 20, 2);


/**
 * ------------------------------------------------------------------
 * 3. FRONTEND: ORDER BY RWPP global order
 * ------------------------------------------------------------------
 */
add_filter('posts_orderby', function ($orderby, WP_Query $q) {

    if (is_admin()) {
        return $orderby;
    }

    $pt = $q->get('post_type');

    $is_shop_like_query =
        is_array($pt) &&
        in_array('product_variation', $pt, true);

    if (! $is_shop_like_query) {
        return $orderby;
    }

    global $wpdb;

    return "
        COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC,
        {$wpdb->posts}.post_title ASC
    ";
}, 20, 2);
