<?php

/**
 * List all products
 *
 * @package ReWooProducts
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Determine category ID (0 for global, or specific term_id).
$rwpp_current_term_id = 0;
if (isset($_GET['term_id']) && ! empty($_GET['term_id'])) { // phpcs:ignore WordPress.Security.NonceVerification
    $rwpp_current_term_id = absint(wp_unslash($_GET['term_id'])); // phpcs:ignore WordPress.Security.NonceVerification
}

// Define callbacks for custom table sorting.
$rwpp_join_callback = function ($join) use (&$rwpp_current_term_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rwpp_product_order';
    $join .= " LEFT JOIN {$table_name} AS rwpp_order
			   ON {$wpdb->posts}.ID = rwpp_order.product_id
			   AND rwpp_order.category_id = " . absint($rwpp_current_term_id);
    return $join;
};

$rwpp_orderby_callback = function ($orderby) {
    global $wpdb;
    return "COALESCE(rwpp_order.sort_order, {$wpdb->posts}.menu_order, 9999) ASC, {$wpdb->posts}.post_title ASC";
};

$rwpp_args = array(
    'post_type'      => array('product'),
    'posts_per_page' => 100,
    'post_status'    => array('publish'),
);

if ($rwpp_current_term_id > 0) {
    $rwpp_args['tax_query'] = array( // phpcs:ignore
        array(
            'taxonomy' => 'product_cat',
            'terms'    => array($rwpp_current_term_id),
            'field'    => 'id',
            'operator' => 'IN',
        ),
    );
}

// Add filters to use custom table for sorting.
add_filter('posts_join', $rwpp_join_callback, 10, 1);
add_filter('posts_orderby', $rwpp_orderby_callback, 10, 1);

$rwpp_products = new WP_Query($rwpp_args);

// Clean up filters after query.
remove_filter('posts_join', $rwpp_join_callback, 10);
remove_filter('posts_orderby', $rwpp_orderby_callback, 10);

if ($rwpp_products->have_posts()) : ?>
    <!-- Search Box -->
    <div class="rwpp-search-wrapper" style="margin-bottom:15px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;">
        <div style="display:flex;gap:10px;align-items:center;">
            <input type="text"
                id="rwpp-search-input"
                placeholder="Search products by name, SKU, or ID..."
                style="flex:1;padding:10px 15px;border:1px solid #ddd;border-radius:4px;font-size:14px;" />
            <button id="rwpp-search-btn" class="button button-primary" style="padding:10px 20px;">
                <span class="dashicons dashicons-search" style="margin-top:3px;"></span> Search
            </button>
            <button id="rwpp-clear-search-btn" class="button" style="padding:10px 20px;">
                <span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span> Clear
            </button>
        </div>
        <div id="rwpp-search-results-info" style="margin-top:10px;color:#666;font-size:13px;display:none;">
            <span class="dashicons dashicons-info" style="color:#2271b1;"></span>
            <span id="rwpp-search-results-text"></span>
        </div>
    </div>

    <div class="rwpp-product-count">
        <?php
        /* translators: %d: number of products */
        printf(esc_html__('Found %d products', 'rearrange-woocommerce-products'), absint($rwpp_products->found_posts));
        ?>
    </div>
    <div class="rwpp-scrollable-wrapper">
        <div id="rwpp-products-list" data-paged="1" data-max-pages="<?php echo esc_attr($rwpp_products->max_num_pages); ?>" data-term-id="<?php echo esc_attr($rwpp_current_term_id); ?>">
            <?php
            $rwpp_serial_no = 1;
            while ($rwpp_products->have_posts()) :
                $rwpp_products->the_post();
                global $post;
                $rwpp_product = wc_get_product($post->ID); // output escaped via WooCommerce wc_get_product().

                // Skip if product is invalid
                if (!$rwpp_product || !is_object($rwpp_product)) {
                    continue;
                }

                $product = $rwpp_product; // Alias for product.php template.
                include 'product.php';
                $rwpp_serial_no++;
            endwhile;
            ?>
        </div>
        <!-- Load More Button -->
        <?php if ($rwpp_products->max_num_pages > 1) : ?>
            <div class="rwpp-load-more-container">
                <button id="rwpp-load-more-btn" class="button button-secondary">
                    <?php esc_html_e('Load More Products', 'rearrange-woocommerce-products'); ?>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="rwpp-footer">
        <div class="rwpp-footer-actions">
            <button id="rwpp-save-orders" class="button button-primary button-large"><?php esc_html_e('Save Changes', 'rearrange-woocommerce-products'); ?></button>
        </div>

        <p class="rwpp-important-note">
            <?php esc_html_e('Use "single click" to select multiple products and drag them.', 'rearrange-woocommerce-products'); ?>
        </p>
    </div><!-- .rwpp-footer -->

    <!-- Search JavaScript -->
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $searchInput = $('#rwpp-search-input');
            var $searchBtn = $('#rwpp-search-btn');
            var $clearBtn = $('#rwpp-clear-search-btn');
            var $productsList = $('#rwpp-products-list');
            var $resultsInfo = $('#rwpp-search-results-info');
            var $resultsText = $('#rwpp-search-results-text');
            var isSearching = false;
            var currentSearchTerm = '';
            var currentMatchIndex = -1; // Track current match position
            var allMatches = []; // Store all matched products

            // Create loading overlay
            var $loadingOverlay = $('<div class="rwpp-loading-overlay" style="display:none;position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.8);z-index:999;"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;"><span class="dashicons dashicons-update rwpp-spinner" style="font-size:48px;color:#2271b1;width:48px;height:48px;display:inline-block;"></span><p style="margin-top:10px;font-size:14px;color:#666;">Loading products...</p></div></div>');

            // Add CSS for spinner animation
            if (!$('#rwpp-spinner-style').length) {
                $('head').append('<style id="rwpp-spinner-style">@keyframes rwpp-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .rwpp-spinner { animation: rwpp-spin 1s linear infinite; }</style>');
            }

            // Append overlay to products container
            var $productsContainer = $productsList.parent();
            if ($productsContainer.css('position') === 'static') {
                $productsContainer.css('position', 'relative');
            }
            $productsContainer.append($loadingOverlay);

            // Show/hide loading overlay
            function showLoading() {
                $loadingOverlay.fadeIn(200);
            }

            function hideLoading() {
                $loadingOverlay.fadeOut(200);
            }

            // Function to filter and highlight matches
            function filterProducts(searchTerm) {
                var matchCount = 0;
                allMatches = []; // Reset matches array

                $('#rwpp-products-list .rwpp-product').each(function() {
                    var $product = $(this);
                    var productName = $product.find('.rwpp-product-name').text().toLowerCase();
                    var productSku = $product.find('.rwpp-product-sku').text().toLowerCase();
                    var productId = $product.attr('data-id') || '';

                    if (productName.includes(searchTerm) ||
                        productSku.includes(searchTerm) ||
                        productId.includes(searchTerm)) {
                        allMatches.push($product);
                        matchCount++;
                    } else {
                        $product.css('background-color', '');
                    }
                });

                return matchCount;
            }

            // Function to highlight and scroll to specific match
            function highlightMatch(index) {
                // Clear all highlights first
                $('#rwpp-products-list .rwpp-product').css('background-color', '');

                if (index >= 0 && index < allMatches.length) {
                    var $match = allMatches[index];
                    $match.css('background-color', '#ffffcc');

                    // Scroll to match
                    setTimeout(function() {
                        $match[0].scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }, 300);

                    // Auto-remove highlight after 3 seconds
                    setTimeout(function() {
                        $match.css('background-color', '');
                    }, 3000);
                }
            }

            // Check and trigger load more if needed
            function checkAndLoadMore() {
                if (!isSearching) return;

                // Remember the old match count
                var oldMatchCount = allMatches.length;

                var matches = filterProducts(currentSearchTerm);

                if (matches > 0) {
                    // Found at least one match
                    // If this is initial search, start from first match
                    if (currentMatchIndex < 0) {
                        currentMatchIndex = 0;
                    }
                    // If we were loading more to find next match, use the old index
                    // (filterProducts will have rebuilt allMatches array with new products)
                    else if (oldMatchCount > 0 && matches > oldMatchCount) {
                        // New products loaded - currentMatchIndex should already be set correctly
                        // Just make sure it's within bounds
                        if (currentMatchIndex >= allMatches.length) {
                            currentMatchIndex = allMatches.length - 1;
                        }
                    }
                    // Otherwise keep current index
                    else if (currentMatchIndex >= allMatches.length) {
                        currentMatchIndex = allMatches.length - 1;
                    }

                    highlightMatch(currentMatchIndex);

                    $resultsText.text('Match ' + (currentMatchIndex + 1) + ' of ' + matches + ' for "' + currentSearchTerm + '"');
                    $resultsInfo.show();
                    $searchBtn.prop('disabled', false).html('<span class="dashicons dashicons-search" style="margin-top:3px;"></span> Search');
                    isSearching = false;
                    hideLoading();
                } else {
                    // No matches yet - check if there are more products to load
                    var $loadMoreBtn = $('#rwpp-load-more-btn');
                    var $loadMoreContainer = $('.rwpp-load-more-container');

                    // Check: button exists, container visible, not disabled, and not hidden by RWPP
                    var canLoadMore = $loadMoreBtn.length > 0 &&
                        $loadMoreContainer.is(':visible') &&
                        !$loadMoreBtn.prop('disabled') &&
                        $loadMoreBtn.is(':visible');

                    if (canLoadMore) {
                        // Continue loading more products to search
                        $searchBtn.text('Loading more products...');
                        $loadMoreBtn.trigger('click');
                    } else {
                        // No more products to load and no matches found
                        $resultsText.text('No products found matching "' + currentSearchTerm + '"');
                        $resultsInfo.show();
                        $searchBtn.prop('disabled', false).html('<span class="dashicons dashicons-search" style="margin-top:3px;"></span> Search');
                        isSearching = false;
                        hideLoading();
                        currentMatchIndex = -1;
                        allMatches = [];
                    }
                }
            }

            // Monitor for new products loaded (via MutationObserver)
            var observer = new MutationObserver(function(mutations) {
                if (isSearching) {
                    // Small delay to ensure DOM is fully updated
                    setTimeout(checkAndLoadMore, 500);
                }
            });

            // Start observing the products list
            if ($productsList.length) {
                observer.observe($productsList[0], {
                    childList: true,
                    subtree: true
                });
            }

            // Search by filtering visible products and auto-loading more
            function performSearch() {
                var searchTerm = $searchInput.val().trim().toLowerCase();

                if (!searchTerm) {
                    alert('Please enter a search term');
                    return;
                }

                // Check if same search term AND we already have matches - move to next match
                var isSameTerm = (searchTerm === currentSearchTerm);
                var hasMatches = (allMatches.length > 0);
                var hasValidIndex = (currentMatchIndex >= 0);

                if (isSameTerm && hasMatches && hasValidIndex) {
                    currentMatchIndex++;

                    // If reached end of current matches, try to load more
                    if (currentMatchIndex >= allMatches.length) {
                        var $loadMoreBtn = $('#rwpp-load-more-btn');
                        var $loadMoreContainer = $('.rwpp-load-more-container');

                        // Check: button exists, container visible, not disabled, and not hidden by RWPP
                        var canLoadMore = $loadMoreBtn.length > 0 &&
                            $loadMoreContainer.is(':visible') &&
                            !$loadMoreBtn.prop('disabled') &&
                            $loadMoreBtn.is(':visible');

                        // Check if there are more products to load
                        if (canLoadMore) {
                            isSearching = true;
                            $searchBtn.prop('disabled', true).text('Loading more...');
                            showLoading();
                            $loadMoreBtn.trigger('click');
                            return;
                        } else {
                            // No more products - loop back to start
                            currentMatchIndex = 0;
                        }
                    }

                    highlightMatch(currentMatchIndex);
                    $resultsText.text('Match ' + (currentMatchIndex + 1) + ' of ' + allMatches.length + ' for "' + currentSearchTerm + '"');
                    $resultsInfo.show();
                    return;
                }

                // New search term or first search - start fresh
                currentSearchTerm = searchTerm;
                currentMatchIndex = -1;
                allMatches = [];
                isSearching = true;
                $searchBtn.prop('disabled', true).text('Searching...');
                showLoading();

                checkAndLoadMore();
            }

            // Clear search - show all products again
            function clearSearch() {
                isSearching = false;
                currentSearchTerm = '';
                currentMatchIndex = -1;
                allMatches = [];
                $searchInput.val('');
                $('#rwpp-products-list .rwpp-product').css('background-color', '');
                $resultsInfo.hide();
            }

            // Event listeners
            $searchBtn.on('click', performSearch);
            $clearBtn.on('click', clearSearch);

            $searchInput.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    performSearch();
                }
            });
        });
    </script>
<?php
endif;

wp_reset_postdata();
