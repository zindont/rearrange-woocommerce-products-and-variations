<?php

/**
 * Plugin Name: Rearrange Products for WooCommerce and Variations
 * Description: Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product" (Free/Pro)
 * Author:            An Ho
 * Author URI:        https://www.linkedin.com/in/andeptrai/
 * Version: 1.0.5
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * -------------------------------------------------------------------
 * CONFIG
 * -------------------------------------------------------------------
 * If your WVASP (Variations as Single Product) plugin makes private (disabled) variations appear on frontend,
 * enable this to force-hide any non-publish items for visitors (and for users who cannot read private products).
 */
if ( ! defined('RWPPV_FRONTEND_HIDE_PRIVATE') ) {
    define('RWPPV_FRONTEND_HIDE_PRIVATE', true);
}

/**
 * Helper: Identify RWPP v5 list query signature (page + ajax)
 * - post_type   = ['product']
 * - post_status = ['publish']
 */
function rwppv_is_rwpp_list_query(WP_Query $q): bool {
    $pt     = $q->get('post_type');
    $status = $q->get('post_status');

    return is_array($pt) && $pt === ['product']
        && is_array($status) && $status === ['publish'];
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
 * Legacy WVASP exclusion (best-effort).
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
 * 1) ADMIN: RWPP page – include product_variation in main list query
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

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);

}, 20);

/**
 * ------------------------------------------------------------------
 * 1b) ADMIN: RWPP "Sort by Categories" page – include product_variation
 * ------------------------------------------------------------------
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if ( ! is_admin() ) {
        return;
    }

    if ( empty($_GET['page']) || $_GET['page'] !== 'rwpp-sortby-categories-page' ) {
        return;
    }

    if ( empty($_GET['term_id']) ) {
        return;
    }

    if ( ! rwppv_is_rwpp_list_query($q) ) {
        return;
    }

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);

}, 20);

/**
 * ------------------------------------------------------------------
 * 2) ADMIN AJAX: RWPP "Load more products" – include variations too
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

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);

}, 20);

/**
 * ------------------------------------------------------------------
 * FRONTEND (FIX ADMIN vs CUSTOMER):
 * Apply RWPP global ordering to the MAIN SHOP QUERY for everyone.
 *
 * Why:
 * - Admin may bypass cache and/or WVASP may alter post_type differently per role.
 * - Restricting to "query includes product_variation" can cause guest to miss ordering.
 *
 * Scope:
 * - Only main query
 * - Only is_shop()
 * - Global order only (category_id = 0)
 * ------------------------------------------------------------------
 */
function rwppv_is_main_catalog_query(WP_Query $q): bool
{
    if (is_admin()) return false;
    if (!$q->is_main_query()) return false;

    $is_shop = function_exists('is_shop') && is_shop();
    $is_cat  = function_exists('is_product_category') && is_product_category();

    if (!$is_shop && !$is_cat) return false;

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

function rwppv_get_rwpp_category_id_for_request(): int
{
    if (function_exists('is_product_category') && is_product_category()) {
        return (int) get_queried_object_id();
    }
    return 0;
}

/**
 * FRONTEND: Ensure private (disabled) items never leak to visitors.
 * Some "variations as single products" implementations may broaden post_status for logged-in users.
 */
add_action('pre_get_posts', function (WP_Query $q) {

    if (is_admin()) {
        return;
    }

    if (!RWPPV_FRONTEND_HIDE_PRIVATE) {
        return;
    }

    // Only affect main catalog queries (shop + product category)
    if (!rwppv_is_main_catalog_query($q)) {
        return;
    }

    // Force-hide any non-publish items for EVERYONE (including admins) on frontend.
    // This prevents WVASP from leaking disabled/private variations on catalog pages.
    $q->set('post_status', ['publish']);

}, 15);
add_filter('posts_join', function ($join, WP_Query $q) {

    if (! rwppv_is_main_catalog_query($q)) {
        return $join;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rwpp_product_order';

    $category_id = rwppv_get_rwpp_category_id_for_request();

    // Join category-specific order
    if (strpos($join, " {$table} AS rwpp_order") === false) {
        $join .= $wpdb->prepare(
            " LEFT JOIN {$table} AS rwpp_order
              ON {$wpdb->posts}.ID = rwpp_order.product_id
              AND rwpp_order.category_id = %d",
            $category_id
        );
    }

    // On category pages, also join global order as fallback
    if (function_exists('is_product_category') && is_product_category()) {
        if (strpos($join, " {$table} AS rwpp_order_global") === false) {
            $join .= " LEFT JOIN {$table} AS rwpp_order_global
                       ON {$wpdb->posts}.ID = rwpp_order_global.product_id
                       AND rwpp_order_global.category_id = 0";
        }
    }

    return $join;
}, 20, 2);

add_filter('posts_orderby', function ($orderby, WP_Query $q) {

    if (! rwppv_is_main_catalog_query($q)) {
        return $orderby;
    }

    global $wpdb;

    // Category page: prefer category order, fallback to global, then menu_order
    if (function_exists('is_product_category') && is_product_category()) {
        return "COALESCE(rwpp_order.sort_order, rwpp_order_global.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
    }

    // Shop: global order
    return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
}, 20, 2);
