# Forbes Product Sync

**Contributors:** Marc Maninang ([@microcurse](https://github.com/microcurse))
**Requires at least:** WordPress 5.8
**Requires PHP:** 7.4
**Requires WooCommerce:** Yes
**Version:** 1.1.0
**License:** GPL v2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Plugin URI:** https://github.com/microcurse
**Text Domain:** forbes-product-sync
**Domain Path:** /languages

Pulls products from source site into the destination site using WooCommerce REST API. This plugin facilitates the synchronization of product **attributes, terms, and core product data** between a source WooCommerce store and the current WooCommerce store. Destination is where this plugin is installed.

## Description

Forbes Product Sync is designed to keep product information consistent between a primary source WooCommerce site and a destination site. It handles:

*   **Product Attributes & Terms:** Syncs attributes (like color, size) and their associated terms (like red, blue, small, medium).
*   **Core Product Data:** Syncs essential product details including product types (simple, variable), descriptions, SKU, pricing, dimensions, images, categories, and tags.

It achieves this by:

*   Connecting to a source WooCommerce store via its REST API.
*   Fetching attribute and term data from the source.
*   Comparing this source data with the attributes and terms present on the local (portal) site.
*   Providing an interface to view these differences (new attributes/terms, updated terms, terms present locally but not in the source).
*   Allowing administrators to selectively synchronize attributes, terms, **and products**, creating new ones or updating existing ones as needed on the portal site.
*   Logging synchronization activities.

This plugin is particularly useful for scenarios where a central product catalog needs to be mirrored or partially replicated on other WordPress/WooCommerce installations.

## Features

*   **Attribute Synchronization:** Syncs product attributes (e.g., Color, Size) from a source WooCommerce store.
*   **Term Synchronization:** Syncs terms for each attribute (e.g., Red, Blue for Color; Small, Medium for Size).
*   **Product Data Synchronization (Planned & In Development):**
    *   Sync core product information: Title, SKU, Product Type (Simple/Variable), Descriptions (long/short).
    *   Handle creation of Simple and Variable products.
    *   For Variable products:
        *   Automatically create a single default variation accepting any attribute options.
        *   Set a default price on this variation to ensure product is in stock.
        *   Set default terms for variation attributes on the parent product.
    *   Update product fields: Regular Price, Dimensions (Length, Width, Height), Slug.
    *   Synchronize Product Images (Featured Image and Gallery).
    *   Synchronize Product Categories and Tags (creating them if they don't exist).
    *   Compare and update Meta Title & Meta Description (basic support).
*   **Comparison Interface:** Provides a clear table-based comparison of source vs. local attributes, terms, **and products**, highlighting differences.
    *   New attributes/terms/products found in the source.
    *   Attributes/terms/products present locally but not in the source.
    *   Terms that have updated names, slugs, or descriptions.
    *   **Products with differing data (e.g., price, SKU, description).**
    *   Changes in term metadata (e.g., term suffix, swatch image).
*   **Selective Sync:** Allows administrators to select which attributes, terms, **and products** to sync.
*   **Connection Management (Planned):**
    *   Display Source and Destination site information (Site Title, URL).
    *   "Connection Test" button to verify API credentials.
*   **Setup Wizard (Planned):** Guided initial setup for easier configuration.
*   **Logging:** Records synchronization actions, successes, and errors for auditing.
*   **API Integration:** Uses WooCommerce REST API for fetching data from the source site.
*   **Custom Table:** Uses a custom database table (`wp_forbes_product_sync_log`) for logging.

## Installation

1.  **Prerequisites:**
    *   Ensure you have WooCommerce installed and activated on both the source site and the destination site.
    *   Obtain WooCommerce REST API Consumer Key and Consumer Secret from the **source** site (WooCommerce > Settings > Advanced > REST API). The key should have Read permissions for pulling data. If bi-directional sync becomes a feature, Write permissions would be needed on the respective source.
2.  **Plugin Installation:**
    *   *(Planned: A setup wizard will guide you through steps 3 & 4 upon activation).*
    *   Download the plugin `forbes-product-sync.zip` (if applicable) or clone the repository.
    *   Upload the plugin files to the `/wp-content/plugins/forbes-product-sync` directory on your **portal** site, or install the plugin through the WordPress plugins screen directly.
    *   Activate the plugin through the 'Plugins' screen in WordPress.
3.  **Configuration:**
    *   Navigate to the plugin's settings page (WooCommerce > Product Sync > Settings).
    *   Enter the **API URL** of your source WooCommerce store (e.g., `https://yourlivesite.com`).
    *   Enter the **Consumer Key** and **Consumer Secret** obtained from the source site.
    *   Configure any other relevant settings, such as a `sync_tag` (default: `sync-this`).
    *   Save the settings.

## Usage

1.  **Navigate to the Sync Interface:**
    *   Once configured, go to the synchronization pages within the WordPress admin area:
        *   Attribute Sync: WooCommerce > Product Sync > Attributes
        *   **Product Sync: WooCommerce > Product Sync > Products (Planned)**
2.  **Fetch and Compare:**
    *   There will be options to fetch and compare attributes or products from the source site.
    *   The plugin will display a comparison table showing differences.
3.  **Review Differences:**
    *   **New Attribute/Term/Product:** Will be created on the portal site if selected.
    *   **Updated Term/Product:** Shows changes in data. Will be updated on the portal site if selected.
    *   **Local Attribute/Term/Product Only:** Indicates an item exists on the portal but not in the source.
    *   **Item OK:** No differences found.
4.  **Select and Synchronize:**
    *   Use the checkboxes to select the items you wish to synchronize.
    *   Click the "Sync Selected" button.
5.  **Check Logs:**
    *   Review the synchronization logs for details of the operations performed, successes, or any errors encountered.

*(Please add more specific steps, screenshots, or GIFS if possible to illustrate the usage clearly)*

## Plugin Structure

The plugin's main functionalities are organized within the `includes/` directory:

*   `class-forbes-product-sync.php`: Core plugin logic and initialization.
*   `includes/api/`: Handles communication with the source WooCommerce REST API.
    *   `class-forbes-product-sync-api-attributes.php`: Specifically for attribute and term related API calls.
    *   **`class-forbes-product-sync-api-products.php` (Planned): For product related API calls.**
*   `includes/attributes/`: Manages attribute and term data.
    *   `class-forbes-product-sync-attributes-handler.php`: Handles the creation, updating, and storage of attributes and terms locally.
    *   `class-forbes-product-sync-attributes-comparison.php`: Compares source and local attributes/terms and prepares data for the comparison UI.
*   **`includes/products/` (Planned): Manages product data.**
    *   **`class-forbes-product-sync-products-handler.php` (Planned): Handles creation, updating, storage of products.**
    *   **`class-forbes-product-sync-products-comparison.php` (Planned): Compares source/local products.**
*   `includes/admin/`: Contains classes for the WordPress admin interface.
    *   `class-forbes-product-sync-admin.php`: Sets up admin menus and pages.
    *   `class-forbes-product-sync-attributes-page.php`: Renders the attribute comparison and sync page.
    *   **`class-forbes-product-sync-products-page.php` (Planned): Renders product comparison/sync page.**
*   `includes/logging/`:
    *   `class-forbes-product-sync-logger.php`: Manages logging of sync activities to the custom database table.
*   `includes/utils/`: Utility functions.
*   `templates/`: HTML templates for admin pages.

## Hooks and Filters

*   `forbes_product_sync_before_sync_term (array $term_data, string $taxonomy)`
*   `forbes_product_sync_after_sync_term (int $term_id, array $term_data, string $taxonomy)`
*   **`forbes_product_sync_before_sync_product (array $product_data)` (Planned)**
*   **`forbes_product_sync_after_sync_product (int $product_id, array $product_data)` (Planned)**

## Frequently Asked Questions (FAQ)

*   **Q: What happens if an attribute or term is deleted from the source site?**
    *   A: Currently, the plugin primarily handles additions and updates from the source. Items deleted on the source will appear as "Local Attribute/Term Only" in the comparison. You would need to manually delete them from the portal site if desired. Future versions might offer an option to handle deletions.
*   **Q: Can I sync other product data like prices or stock?**
    *   A: **Yes, full product data synchronization (including prices, stock, descriptions, images, types, etc.) is a core planned feature and is currently in development as per our roadmap.** This version initially focuses on attributes and terms, but product sync is a high priority.
*   **Q: How are product variations handled during sync?**
    *   A: **(Planned Feature)** When a variable product is synced for the first time, the plugin will aim to create a single default variation. This variation will be set up to use "Any" for its attributes, and a default price will be assigned to ensure the product is available. Specific variation details beyond this initial setup will be part of ongoing development.
*   **Q: How are conflicts handled?**
    *   A: The comparison table will show you the differences. For updates, the source data is generally considered the "truth." You can choose not to sync specific items if you wish to keep the local version.

## Changelog

### 1.1.0
*   Feature: Attribute and term synchronization from a source WooCommerce store.
*   Feature: Comparison UI for attributes and terms.
*   Feature: Logging of sync operations.
*   **Foundation for future product synchronization.**

### 1.0.0 (Date of release)
*   Initial public release.
*   API settings and configration page set up
*   Initial design and engineering for attribute and product sync structure.

## Contributing

Contributions are welcome! If you'd like to contribute, please:

1.  Fork the repository.
2.  Create a new branch for your feature or bug fix.
3.  Commit your changes.
4.  Push your branch and submit a pull request.

Please ensure your code follows WordPress coding standards.

## Support

If you encounter any issues or have questions, please open an issue on the [GitHub repository](https://github.com/microcurse/forbes-product-sync/issues).

---

This plugin was developed by Marc Maninang.
