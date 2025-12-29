<?php

/**
 * Custom Master Template - Override RWPP
 *
 * @package RWPPV
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include RWPP's original header and footer
$rwpp_views_path = WP_PLUGIN_DIR . '/rearrange-woocommerce-products/views/';
?>
<?php require $rwpp_views_path . 'template-parts/header.php'; ?>

<div class="rwpp-content-wrapper">

    <input type="hidden" name="rwpp_current_page_url" id="rwpp_current_page_url" value="<?php echo isset($_SERVER['REQUEST_URI']) ? esc_attr(wp_unslash($_SERVER['REQUEST_URI'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized 
                                                                                        ?>">

    <?php
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

    if ('rwpp-sortby-categories-page' === $page) {
        // Use custom category template if exists, otherwise use original
        $custom_template = RWPPV_PATH . 'views/template-parts/tab-category-products.php';
        if (file_exists($custom_template)) {
            include $custom_template;
        } else {
            include $rwpp_views_path . 'template-parts/tab-category-products.php';
        }
    } elseif ('rwpp-troubleshooting-page' === $page) {
        include $rwpp_views_path . 'template-parts/tab-troubleshooting.php';
    } elseif ('rwpp-settings-page' === $page) {
        include $rwpp_views_path . 'template-parts/tab-settings.php';
    } else {
        // Use custom all-products template if exists, otherwise use original
        $custom_template = RWPPV_PATH . 'views/template-parts/tab-all-products.php';
        if (file_exists($custom_template)) {
            include $custom_template;
        } else {
            include $rwpp_views_path . 'template-parts/tab-all-products.php';
        }
    }
    ?>

</div>

<?php require $rwpp_views_path . 'template-parts/footer.php'; ?>