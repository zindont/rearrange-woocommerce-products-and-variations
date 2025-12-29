<?php

/**
 * Plugin Name: Rearrange Products for WooCommerce and Variations
 * Description: Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product" (Free/Pro)
 * Author:            An Ho
 * Author URI:        https://www.linkedin.com/in/andeptrai/
 * Version: 1.0.6
 */

if (! defined('ABSPATH')) {
    exit;
}

// Define plugin path
if (! defined('RWPPV_PATH')) {
    define('RWPPV_PATH', plugin_dir_path(__FILE__));
}

if (! defined('RWPPV_URL')) {
    define('RWPPV_URL', plugin_dir_url(__FILE__));
}

/**
 * -------------------------------------------------------------------
 * CONFIG
 * -------------------------------------------------------------------
 * If your WVASP (Variations as Single Product) plugin makes private (disabled) variations appear on frontend,
 * enable this to force-hide any non-publish items for visitors (and for users who cannot read private products).
 */
if (! defined('RWPPV_FRONTEND_HIDE_PRIVATE')) {
    define('RWPPV_FRONTEND_HIDE_PRIVATE', true);
}

/**
 * Helper: Identify RWPP v5 list query signature (page + ajax)
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
 * WVASP mode check
 */
function rwppv_is_wvasp_legacy_mode(): bool
{
    return get_option('wvasp_legacy_product_exclude', 'no') === 'yes';
}

/**
 * Non-legacy WVASP exclusion:
 * Exclude posts where _wvasp_exclude = 'yes'
 */
function rwppv_apply_wvasp_exclude_meta_rule(WP_Query $q): void
{
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
function rwppv_get_wvasp_legacy_exclude_ids(): array
{
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
function rwppv_apply_wvasp_exclusions(WP_Query $q): void
{
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

    if (! is_admin()) {
        return;
    }

    if (empty($_GET['page']) || $_GET['page'] !== 'rwpp-page') {
        return;
    }

    if (! rwppv_is_rwpp_list_query($q)) {
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

    if (! is_admin()) {
        return;
    }

    if (empty($_GET['page']) || $_GET['page'] !== 'rwpp-sortby-categories-page') {
        return;
    }

    if (empty($_GET['term_id'])) {
        return;
    }

    if (! rwppv_is_rwpp_list_query($q)) {
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

    if (! (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    if (empty($_REQUEST['action']) || $_REQUEST['action'] !== 'load_more_products') {
        return;
    }

    if (! rwppv_is_rwpp_list_query($q)) {
        return;
    }

    // Include variations
    $q->set('post_type', ['product', 'product_variation']);

    // Apply WVASP exclusions (supports Pro + legacy)
    rwppv_apply_wvasp_exclusions($q);
}, 20);

/**
 * ------------------------------------------------------------------
 * OVERRIDE RWPP TEMPLATE
 * Use ReflectionClass to replace the callback function
 * ------------------------------------------------------------------
 */
add_action('admin_menu', function () {
    global $wp_filter;

    // Find and replace the RWPP callback
    if (isset($wp_filter['admin_menu'])) {
        foreach ($wp_filter['admin_menu']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                if (
                    is_array($callback['function']) &&
                    is_object($callback['function'][0]) &&
                    get_class($callback['function'][0]) === 'ReWooProducts\Plugin'
                ) {

                    // Remove the original callback
                    remove_action('admin_menu', $callback['function'], $priority);

                    // Add our custom callback at the same priority
                    add_action('admin_menu', 'rwppv_custom_register_admin_menus', $priority);
                    break 2;
                }
            }
        }
    }
}, 9); // Run before RWPP's admin_menu (which is at priority 10)

/**
 * Custom admin menu registration (replaces RWPP's)
 */
function rwppv_custom_register_admin_menus()
{
    $user = wp_get_current_user();
    $role = (array) $user->roles;

    if (in_array('administrator', $role, true)) {
        rwppv_add_custom_pages('manage_options');
    } elseif (in_array('shop_manager', $role, true)) {
        rwppv_add_custom_pages('shop_manager');
    } elseif (current_user_can('manage_woocommerce')) {
        rwppv_add_custom_pages('manage_woocommerce');
    }
}

/**
 * Add pages with custom callback
 */
function rwppv_add_custom_pages($role)
{
    add_menu_page(
        __('Rearrange Products', 'rearrange-woocommerce-products'),
        __('Rearrange Products', 'rearrange-woocommerce-products'),
        $role,
        'rwpp-page',
        'rwppv_custom_page_callback',
        'dashicons-screenoptions',
        '55.5'
    );

    add_submenu_page(
        'rwpp-page',
        __('Sort by Categories', 'rearrange-woocommerce-products'),
        __('Sort by Categories', 'rearrange-woocommerce-products'),
        $role,
        'rwpp-sortby-categories-page',
        'rwppv_custom_page_callback'
    );

    add_submenu_page(
        'rwpp-page',
        __('Settings', 'rearrange-woocommerce-products'),
        __('Settings', 'rearrange-woocommerce-products'),
        $role,
        'rwpp-settings-page',
        'rwppv_custom_page_callback'
    );

    add_submenu_page(
        'rwpp-page',
        __('Troubleshooting', 'rearrange-woocommerce-products'),
        __('Troubleshooting', 'rearrange-woocommerce-products'),
        $role,
        'rwpp-troubleshooting-page',
        'rwppv_custom_page_callback'
    );
}

/**
 * Custom page callback - loads our template
 */
function rwppv_custom_page_callback()
{
    include RWPPV_PATH . 'views/rearrange-all-products.php';
}

/**
 * ------------------------------------------------------------------
 * OVERRIDE RWPP AJAX - Load More Products Handler
 * ------------------------------------------------------------------
 */
add_action('wp_ajax_load_more_products', 'rwppv_custom_load_more_products_handler', 1);

function rwppv_custom_load_more_products_handler()
{
    try {
        // Increase execution time for pagination queries
        if (function_exists('set_time_limit')) {
            set_time_limit(60);
        }

        // Security validation (simplified - RWPP will also check)
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'rwpp-ajax-nonce')) {
            wp_send_json_error(['message' => 'Invalid security token']);
        }

        // Get parameters
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 200;
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;

        if ($page < 1) {
            $page = 1;
        }

        if ($per_page < 1 || $per_page > 500) {
            $per_page = 200;
        }

        $term_id = max(0, $term_id);
        $current_term_id = $term_id;

        $join_callback = function ($join) use (&$current_term_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rwpp_product_order';
            $join .= " LEFT JOIN {$table_name} AS rwpp_order
                       ON {$wpdb->posts}.ID = rwpp_order.product_id
                       AND rwpp_order.category_id = " . absint($current_term_id);
            return $join;
        };

        $orderby_callback = function ($orderby) {
            global $wpdb;
            return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
        };

        $args = array(
            'post_type'      => array('product'),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => array('publish'),
        );

        // Add category filter if specified
        if ($term_id > 0) {
            $args['tax_query'] = array(
                array(
                    'taxonomy'         => 'product_cat',
                    'terms'            => array($term_id),
                    'field'            => 'id',
                    'operator'         => 'IN',
                    'include_children' => true,
                ),
            );
        }

        // Apply filters for custom table JOIN
        add_filter('posts_join', $join_callback, 10, 1);
        add_filter('posts_orderby', $orderby_callback, 10, 1);

        // Execute query
        $products = new WP_Query($args);

        // Clean up filters
        remove_filter('posts_join', $join_callback, 10);
        remove_filter('posts_orderby', $orderby_callback, 10);

        // Build HTML for products using our custom template
        ob_start();

        $serial_no = (($page - 1) * $per_page) + 1;

        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                global $post;
                $product = wc_get_product($post->ID);

                // Skip if product is invalid
                if (!$product || !is_object($product)) {
                    continue;
                }

                // Pass term_id to template for correct order display
                $rwpp_current_term_id = $term_id;

                // Include our custom product template
                include RWPPV_PATH . 'views/template-parts/product.php';

                $serial_no++;
            }
        }

        $products_html = ob_get_clean();
        wp_reset_postdata();

        // Determine if there are more pages
        $has_more = $products->max_num_pages > $page;
        $loaded_count = min($page * $per_page, $products->found_posts);

        // Return JSON response
        wp_send_json_success(
            array(
                'products_html' => $products_html,
                'has_more'      => $has_more,
                'total'         => $products->found_posts,
                'loaded'        => $loaded_count,
                'current_page'  => $page,
            )
        );
    } catch (Exception $e) {
        wp_send_json_error(
            array(
                'message' => __('Failed to load products.', 'rearrange-woocommerce-products'),
            )
        );
    }
    die();
}

/**
 * ------------------------------------------------------------------
 * AJAX: Search Products
 * ------------------------------------------------------------------
 */
add_action('wp_ajax_rwppv_search_products', function () {
    check_ajax_referer('rwppv-search-nonce', 'nonce');

    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;

    if (empty($search_term)) {
        wp_send_json_error(['message' => 'Search term is required']);
    }

    // Build query args
    $args = array(
        'post_type'      => array('product', 'product_variation'),
        'posts_per_page' => -1, // Get all matching products
        'post_status'    => array('publish'),
        's'              => $search_term,
    );

    // Also search by SKU and ID
    add_filter('posts_search', function ($search, $wp_query) use ($search_term) {
        global $wpdb;

        if (empty($search)) {
            return $search;
        }

        $search_like = '%' . $wpdb->esc_like($search_term) . '%';

        // Add SKU and ID search
        $search .= $wpdb->prepare(" OR {$wpdb->posts}.ID = %d", $search_term);
        $search .= $wpdb->prepare(" OR EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} 
            WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID 
            AND {$wpdb->postmeta}.meta_key = '_sku' 
            AND {$wpdb->postmeta}.meta_value LIKE %s
        )", $search_like);

        return $search;
    }, 10, 2);

    // Add category filter if specified
    if ($term_id > 0) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'product_cat',
                'terms'    => array($term_id),
                'field'    => 'id',
                'operator' => 'IN',
            ),
        );
    }

    // Setup sorting callbacks
    $current_term_id = $term_id;
    $join_callback = function ($join) use (&$current_term_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rwpp_product_order';
        $join .= " LEFT JOIN {$table_name} AS rwpp_order
                   ON {$wpdb->posts}.ID = rwpp_order.product_id
                   AND rwpp_order.category_id = " . absint($current_term_id);
        return $join;
    };

    $orderby_callback = function ($orderby) {
        global $wpdb;
        return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
    };

    // Apply filters
    add_filter('posts_join', $join_callback, 10, 1);
    add_filter('posts_orderby', $orderby_callback, 10, 1);

    // Execute query
    $products = new WP_Query($args);

    // Clean up filters
    remove_filter('posts_join', $join_callback, 10);
    remove_filter('posts_orderby', $orderby_callback, 10);
    remove_all_filters('posts_search');

    // Build HTML
    ob_start();

    if ($products->have_posts()) {
        while ($products->have_posts()) {
            $products->the_post();
            global $post;
            $product = wc_get_product($post->ID);

            // Skip if product is invalid (deleted, private, etc.)
            if (!$product || !is_object($product)) {
                continue;
            }

            // Pass term_id to template for correct order display
            $rwpp_current_term_id = $term_id;

            // Include our custom product template
            include RWPPV_PATH . 'views/template-parts/product.php';
        }
    } else {
        echo '<div style="padding:40px;text-align:center;color:#666;">';
        echo '<span class="dashicons dashicons-search" style="font-size:48px;opacity:0.3;"></span>';
        echo '<p style="font-size:16px;margin-top:10px;">No products found matching "<strong>' . esc_html($search_term) . '</strong>"</p>';
        echo '</div>';
    }

    $html = ob_get_clean();
    wp_reset_postdata();

    wp_send_json_success([
        'html' => $html,
        'count' => $products->found_posts
    ]);
});

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
