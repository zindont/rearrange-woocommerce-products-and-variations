# Changelog

All notable changes to this project will be documented in this file.

## [1.0.6] - 2025-12-29

### Added

- **Product Search Functionality**: Implemented comprehensive search feature for the product list
  - Search products by name, SKU, or product ID
  - Real-time filtering with visual feedback
  - Auto-load more products until match is found
  - Loading overlay with spinner animation during search
  - Search results counter showing current match position
- **Find Next Feature**: Added nano-style sequential search navigation

  - Keep same search term and click Search again to jump to next match
  - Automatically loads more products when reaching end of current matches
  - Loops back to first match when no more products available
  - Auto-removes highlight after 3 seconds for better UX

- **Custom Template Override System**:
  - Override RWPP (Rearrange WooCommerce Products) display templates
  - Display product order numbers from `wp_rwpp_product_order` table
  - Custom product template with safety validation
  - Increased products per page to 200 for better performance

### Fixed

- Product validation to prevent fatal errors from invalid product IDs
  - Added safety check: `if (!$product || !is_object($product)) continue;`
  - Gracefully skip invalid products instead of crashing
- Order number display in custom templates
  - Correctly pass `$rwpp_current_term_id` to product templates
  - Query uses fallback: `COALESCE(rwpp_order.sort_order, menu_order, 9999)`
- Load more functionality with custom handler
  - Override RWPP's AJAX handler with priority 1
  - Maintain correct product ordering after loading more pages
  - Proper integration with search and filter features

### Technical Details

- Added `rwppv_custom_register_admin_menus()` hook at priority 9 to intercept RWPP menu
- Created custom load more AJAX handler: `rwppv_custom_load_more_products_handler()`
- Implemented MutationObserver to detect newly loaded products during search
- Search state management with variables: `currentSearchTerm`, `currentMatchIndex`, `allMatches[]`
- Client-side filtering for optimal performance with large product lists

## [1.0.5] - Previous Release

### Features

- Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product"
- Support for both Pro and legacy WVASP modes
- Frontend and admin query modifications for proper variation display
- WVASP exclusion rules integration

---

**Note**: This plugin requires:

- WordPress 5.0+
- WooCommerce 4.0+
- Rearrange WooCommerce Products v5.x
- Variations as Single Product (Free or Pro)
