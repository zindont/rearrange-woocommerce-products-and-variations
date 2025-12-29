# Rearrange WooCommerce Products and Variations

**Contributors:** An Ho  
**Tags:** woocommerce, product ordering, variations, product variations, shop ordering  
**Requires at least:** 5.0  
**Tested up to:** 6.4  
**Stable tag:** 1.0.0  
**Requires PHP:** 7.4  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Sync global ordering between Rearrange WooCommerce Products v5.x and "Variations as Single Product" plugin.

## Description

This plugin extends the functionality of **Rearrange WooCommerce Products** (RWPP) v5.x to work seamlessly with product variations displayed as single products through the "Variations as Single Product" plugin.

### Key Features

- **Admin Integration**: Automatically includes product variations in the RWPP admin ordering interface
- **Exclude Rule Sync**: Respects the `_wvasp_exclude` meta key to exclude specific variations from ordering
- **Frontend Ordering**: Applies RWPP global ordering to product variations on the shop pages
- **Seamless Integration**: Works transparently with existing WooCommerce shop queries

### How It Works

1. **Admin Side**: When you access the RWPP ordering page, product variations are automatically included in the sortable list alongside regular products
2. **Frontend Side**: Shop and archive pages display variations in the same order you set in RWPP admin
3. **Fallback Ordering**: If a product/variation doesn't have RWPP ordering, it falls back to menu_order, then alphabetically by title

### Technical Details

The plugin hooks into three key WordPress filters:

- `pre_get_posts` - Modifies admin queries to include variations
- `posts_join` - Joins RWPP ordering table on frontend queries
- `posts_orderby` - Applies RWPP ordering to frontend product lists

## Installation

1. Upload the `rearrange-woocommerce-products-and-variations` folder to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No additional configuration needed - the plugin works automatically once activated

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- **Rearrange WooCommerce Products** v5.x (by aslamdoctor)
- **Variations as Single Product for WooCommerce** plugin (by StorePlugin)

## Frequently Asked Questions

### Does this work with all versions of RWPP?

This plugin is specifically designed for **Rearrange WooCommerce Products v5.x**. It may not work with older versions due to query signature differences.

### Will it affect my site performance?

The plugin uses efficient JOIN queries and only activates on relevant pages. Performance impact should be minimal.

### What if I don't use "Variations as Single Product"?

The plugin will still work but won't have any visible effect since variations won't be displayed as individual products on your shop pages.

### Can I exclude specific variations from ordering?

Yes, the plugin respects the `_wvasp_exclude` meta key. Set it to 'yes' for variations you want to exclude.

## Changelog

### 1.0.0

- Initial release
- Admin integration with RWPP ordering page
- Frontend ordering support for variations
- Exclude rule synchronization with WVASP

## Upgrade Notice

### 1.0.0

Initial release. Install and enjoy synchronized ordering for product variations!

## Support

For support, please contact:

- **Author:** An Ho
- **LinkedIn:** [https://www.linkedin.com/in/andeptrai/](https://www.linkedin.com/in/andeptrai/)

## License

This plugin is licensed under the GPLv2 or later.
