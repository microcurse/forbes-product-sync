<?php
/**
 * Remote Product Class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote Product Class
 */
class Forbes_Product_Sync_Remote {
    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     *
     * @param array $settings Plugin settings.
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }

    /**
     * Get product by SKU
     *
     * @param string $sku Product SKU.
     * @return array|false
     */
    public function get_product($sku) {
        $query = <<<GRAPHQL
            query GetProductBySku($sku: String!) {
                productBySku(sku: $sku) {
                    id
                    sku
                    name
                    description
                    price
                    regularPrice
                    salePrice
                    stockStatus
                    stockQuantity
                    weight
                    length
                    width
                    height
                    images {
                        sourceUrl
                        altText
                    }
                    attributes {
                        name
                        options
                    }
                    categories {
                        name
                        slug
                    }
                    tags {
                        name
                        slug
                    }
                }
            }
        GRAPHQL;

        $variables = array(
            'sku' => $sku
        );

        $response = $this->make_request($query, $variables);
        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['data']['productBySku'] ?? false;
    }

    /**
     * Get products by tag
     *
     * @param string $tag Tag to filter by.
     * @return array
     */
    public function get_products_by_tag($tag) {
        $query = <<<GRAPHQL
            query GetProductsByTag($tag: String!) {
                products(where: { tag: $tag }, first: 100) {
                    nodes {
                        id
                        sku
                        name
                        description
                        price
                        regularPrice
                        salePrice
                        stockStatus
                        stockQuantity
                        weight
                        length
                        width
                        height
                        images {
                            sourceUrl
                            altText
                        }
                        attributes {
                            name
                            options
                        }
                        categories {
                            name
                            slug
                        }
                        tags {
                            name
                            slug
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = array(
            'tag' => $tag
        );

        $response = $this->make_request($query, $variables);
        if (is_wp_error($response)) {
            return array();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['data']['products']['nodes'] ?? array();
    }

    /**
     * Make GraphQL request
     *
     * @param string $query GraphQL query.
     * @param array  $variables Query variables.
     * @return array|WP_Error
     */
    private function make_request($query, $variables = array()) {
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->settings['api_username'] . ':' . $this->settings['api_password']),
            ),
            'body' => json_encode(array(
                'query' => $query,
                'variables' => $variables
            )),
            'timeout' => 30,
        );

        return wp_remote_post($this->settings['api_url'], $args);
    }
} 