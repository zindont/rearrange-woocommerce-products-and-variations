<?php

/**
 * Product Box
 *
 * @package ReWooProducts
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Safety check: ensure $product is valid
if (!$product || !is_object($product)) {
    return; // Skip this product if invalid
}
?>
<div class="rwpp-product" data-id="<?php echo esc_attr($post->ID); ?>">
    <div class="rwpp-product-handle">
        <span class="dashicons dashicons-menu"></span>
    </div>

    <div class="rwpp-product-image">
        <?php echo $product->get_image('thumbnail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
        ?>
    </div>

    <div class="rwpp-product-details">
        <div class="rwpp-product-name"><?php the_title(); ?></div>
        <div class="rwpp-product-meta">
            <span class="rwpp-product-sku">SKU: <?php echo $product->get_sku() ? esc_html($product->get_sku()) : '-'; ?></span>
            <?php if ($product->is_in_stock()) : ?>
                <span class="rwpp-product-stock instock"><?php esc_html_e('Instock', 'rearrange-woocommerce-products'); ?></span>
            <?php else : ?>
                <span class="rwpp-product-stock outofstock"><?php esc_html_e('Outofstock', 'rearrange-woocommerce-products'); ?></span>
            <?php endif; ?>
        </div>

        <?php
        // Get current term ID for category sorting (passed from parent template)
        if (!isset($rwpp_current_term_id)) {
            $rwpp_current_term_id = isset($_GET['term_id']) ? absint($_GET['term_id']) : 0;
        }

        // Get sort order from custom table
        global $wpdb;
        $table_name = $wpdb->prefix . 'rwpp_product_order';
        $sort_order = $wpdb->get_var($wpdb->prepare(
            "SELECT sort_order FROM {$table_name} WHERE product_id = %d AND category_id = %d",
            $post->ID,
            $rwpp_current_term_id
        ));

        // Fallback to menu_order if no custom sort order
        if ($sort_order === null) {
            $sort_order = $post->menu_order;
        }
        ?>
        <div style="margin-top:6px;padding:4px 8px;background:#f8f9fa;border-left:3px solid #0073aa;font-size:11px;">
            <strong style="color:#0073aa;">Order:</strong>
            <span style="font-weight:600;color:#333;"><?php echo esc_html($sort_order); ?></span>
        </div>
    </div>

    <div class="rwpp-product-price">
        <?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
        ?>
    </div>

    <div class="rwpp-product-actions">
        <button class="rwpp-action-btn move-top" title="<?php esc_attr_e('Move to top', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-arrow-up-alt"></span>
        </button>
        <button class="rwpp-action-btn move-up" title="<?php esc_attr_e('Move up', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-arrow-up-alt2"></span>
        </button>
        <button class="rwpp-action-btn move-down" title="<?php esc_attr_e('Move down', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-arrow-down-alt2"></span>
        </button>
        <button class="rwpp-action-btn move-bottom" title="<?php esc_attr_e('Move to bottom', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-arrow-down-alt"></span>
        </button>
        <a href="<?php the_permalink(); ?>" class="rwpp-btn-view" target="_blank" title="<?php esc_attr_e('View', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('View', 'rearrange-woocommerce-products'); ?>
        </a>
        <a href="<?php echo esc_url(get_edit_post_link()); ?>" class="rwpp-btn-edit" target="_blank" title="<?php esc_attr_e('Edit', 'rearrange-woocommerce-products'); ?>">
            <span class="dashicons dashicons-edit"></span> <?php esc_html_e('Edit', 'rearrange-woocommerce-products'); ?>
        </a>
    </div>
</div>