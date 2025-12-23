<?php

/**
 * Plugin Name: Rearrange Products for WooCommerce and Variations
 * Description: Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product" (Free/Pro). Also shows disabled (private) variations in Rearrange admin list.
 * Author:            An Ho
 * Author URI:        https://www.linkedin.com/in/andeptrai/
 * Version: 1.0.2
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Helper: Identify RWPP v5 list query signature (page + ajax)
 * - post_type   = ['product']
 * - post_status is typically ['publish'] (we allow also 'private' so disabled variations can be listed)
 */
function rwppv_is_rwpp_list_query(WP_Query $q): bool {
    $pt     = $q->get('post_type');
    $status = (array) $q->get('post_status');

    $is_product_list = is_array($pt) && $pt === ['product'];

    // Allow only publish/private (RWPP uses publish by default; we extend to private)
    $allowed_statuses = ['publish', 'private'];
    $is_allowed_status =
        !empty($status) &&
        count(array_diff($status, $allowed_statuses)) === 0;

    return $is_product_list && $is_allowed_status;
}

/**
 * WVASP mode check
 */
function rwppv_is_wvasp_legacy_mode(): bool {
    return get_option('wvasp_legacy_product_exclude', 'no') === 'yes';
}

/**
 * Non-legacy WVASP exclusion:
 * Exclude posts where _wvasp_exclude = 'yes'
 */
function rwppv_apply_wvasp_exclude_meta_rule(WP_Query $q): void {
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
}

/**
 * Legacy WVASP exclusion:
 * Build exclude IDs similar to Pro's legacy logic (best-effort).
 */
function rwppv_get_wvasp_legacy_exclude_ids(): array {
    global $wpdb;

    $exclude_ids = [];

    // 1) Hide parent variable products
    $hide_parent_products = get_option('wvasp_hide_parent_products', 'no') === 'yes';
    if ($hide_parent_products && function_exists('wc_get_products')) {
        $parent_ids = wc_get_products([
            'type'   => 'variable',
            'limit'  => -1,
            'return' => 'ids',
        ]);
        if (!empty($parent_ids)) {
            $exclude_ids = array_merge($exclude_ids, $parent_ids);
        }
    }

    // 2) Exclude non-published variation products (or whose parent isn't published)
    $non_published_variations = $wpdb->get_col("
        SELECT v.ID
        FROM {$wpdb->posts} v
        INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent
        WHERE v.post_type = 'product_variation'
          AND (
                v.post_status <> 'publish'
                OR p.post_status <> 'publish'
              )
    ");
    if (!empty($non_published_variations)) {
        $exclude_ids = array_merge($exclude_ids, $non_published_variations);
    }

    // 3) Single-product / single-variation exclusion metas (as seen in Pro)
    // 3a) Parent products with _wvasp_single_exclude_varations = yes => exclude their child variations
    $excluded_parent_ids = $wpdb->get_col("
        SELECT pm.post_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'product'
          AND pm.meta_key = '_wvasp_single_exclude_varations'
          AND pm.meta_value = 'yes'
    ");

    if (!empty($excluded_parent_ids)) {
        $placeholders = implode(',', array_fill(0, count($excluded_parent_ids), '%d'));
        $children = $wpdb->get_col($wpdb->prepare("
            SELECT v.ID
            FROM {$wpdb->posts} v
            WHERE v.post_type = 'product_variation'
              AND v.post_parent IN ($placeholders)
        ", ...$excluded_parent_ids));

        if (!empty($children)) {
            $exclude_ids = array_merge($exclude_ids, $children);
        }
        // Pro does NOT exclude those parents themselves.
    }

    // 3b) Variations with _wvasp_single_exclude_variation = yes
    $excluded_variation_ids = $wpdb->get_col("
        SELECT pm.post_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'product_variation'
          AND pm.meta_key = '_wvasp_single_exclude_variation'
          AND pm.meta_value = 'yes'
    ");
    if (!empty($excluded_variation_ids)) {
        $exclude_ids = array_merge($exclude_ids, $excluded_variation_ids);
    }

    // 3c) Parent products with _wvasp_single_hide_parent_product = yes
    $single_hide_parent_ids = $wpdb->get_col("
        SELECT pm.post_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'product'
          AND pm.meta_key = '_wvasp_single_hide_parent_product'
          AND pm.meta_value = 'yes'
    ");
    if (!empty($single_hide_parent_ids)) {
        $exclude_ids = array_merge($exclude_ids, $single_hide_parent_ids);
    }

    // 4) Exclude out-of-stock variations
    $hide_out_of_stock = get_option('wvasp_hide_out_of_stock_variation_product', 'no') === 'yes';
    if ($hide_out_of_stock && function_exists('wc_get_products')) {
        $out_ids = wc_get_products([
            'type'         => 'variation',
            'limit'        => -1,
            'stock_status' => 'outofstock',
            'return'       => 'ids',
        ]);
        if (!empty($out_ids)) {
            $exclude_ids = array_merge($exclude_ids, $out_ids);
        }
    }

    // 5) Exclude backorder variations
    $hide_backorder = get_option('wvasp_hie_backorder_variation_product', 'no') === 'yes';
    if ($hide_backorder && function_exists('wc_get_products')) {
        $bo_ids = wc_get_products([
            'type'         => 'variation',
            'limit'        => -1,
            'stock_status' => 'onbackorder',
            'return'       => 'ids',
        ]);
        if (!empty($bo_ids)) {
            $exclude_ids = array_merge($exclude_ids, $bo_ids);
        }
    }

    // Let WVASP (Free/Pro) adjust
    $exclude_ids = apply_filters('woo_variations_as_single_product_exclude_ids', $exclude_ids);

    // Normalize
    $exclude_ids = array_values(array_unique(array_map('intval', $exclude_ids)));

    return $exclude_ids;
}

/**
 * Apply WVASP exclusion rules to a query (admin RWPP list + RWPP ajax load more)
 */
function rwppv_apply_wvasp_exclusions(WP_Query $q): void {
    if (rwppv_is_wvasp_legacy_mode()) {
        $exclude_ids = rwppv_get_wvasp_legacy_exclude_ids();
        if (!empty($exclude_ids)) {
            $existing = (array) $q->get('post__not_in', []);
            $q->set('post__not_in', array_values(array_unique(array_merge($existing, $exclude_ids))));
        }
    } else {
        rwppv_apply_wvasp_exclude_meta_rule($q);
    }
}

/**
 * ------------------------------------------------------------------
 * 1) ADMIN: RWPP page – include product_variation + private status
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if ( ! is_admin() ) {
        return;
    }

    if ( empty($_GET['page']) || $_GET['page'] !== 'rwpp-page' ) {
        return;
    }

    if ( ! rwppv_is_rwpp_list_query($q) ) {
        return;
    }

    // Show disabled variations too (Woo stores disabled variation as private)
    $q->set('post_status', ['publish', 'private']);

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);

}, 20);

/**
 * ------------------------------------------------------------------
 * 2) ADMIN AJAX: RWPP "Load more products" – include variations + private status
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if ( ! (defined('DOING_AJAX') && DOING_AJAX) ) {
        return;
    }

    if ( empty($_REQUEST['action']) || $_REQUEST['action'] !== 'load_more_products' ) {
        return;
    }

    if ( ! rwppv_is_rwpp_list_query($q) ) {
        return;
    }

    // Show disabled variations too
    $q->set('post_status', ['publish', 'private']);

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);

}, 20);

/**
 * ------------------------------------------------------------------
 * FRONTEND (ADMIN vs CUSTOMER consistency):
 * Force RWPP global ordering on the MAIN SHOP QUERY for everyone.
 * Global order only (category_id = 0).
 * ------------------------------------------------------------------
 */
function rwppv_is_main_shop_query(WP_Query $q): bool {
    if (is_admin()) return false;
    if (!$q->is_main_query()) return false;
    if (!function_exists('is_shop') || !is_shop()) return false;

    // Ensure it's a product catalog query
    $pt = $q->get('post_type');
    if (is_string($pt)) {
        return $pt === 'product';
    }
    if (is_array($pt)) {
        return in_array('product', $pt, true) || in_array('product_variation', $pt, true);
    }
    return empty($pt);
}

add_filter('posts_join', function ($join, WP_Query $q) {

    if ( ! rwppv_is_main_shop_query($q) ) {
        return $join;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rwpp_product_order';

    if ( strpos($join, $table) === false ) {
        $join .= " LEFT JOIN {$table} AS rwpp_order
                   ON {$wpdb->posts}.ID = rwpp_order.product_id
                   AND rwpp_order.category_id = 0";
    }

    return $join;

}, 20, 2);

add_filter('posts_orderby', function ($orderby, WP_Query $q) {

    if ( ! rwppv_is_main_shop_query($q) ) {
        return $orderby;
    }

    global $wpdb;

    return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";

}, 20, 2);
