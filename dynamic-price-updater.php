<?php

/**
 * Plugin Name: Динамічний оновлювач цін
 * Description: Просте відображення цін для товарів WooCommerce.
 * Version: 1.0.0
 * Text Domain: dynamic-price-updater
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dynamic Price Updater for WooCommerce
 *
 * This plugin modifies the price display for WooCommerce products,
 * hiding the default price and showing a custom price format.
 */
class Dynamic_Price_Updater
{
    /**
     * Constructor - Initialize hooks
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 10, 2);
        add_action('woocommerce_single_product_summary', array($this, 'display_custom_price'), 15);
    }

    /**
     * Enqueue scripts and styles for the plugin
     */
    public function enqueue_scripts()
    {
        if (is_product()) {
            wp_enqueue_script('dynamic-price-updater', plugin_dir_url(__FILE__) . 'dynamic-price-updater.js', array('jquery'), '1.0.0', true);
            wp_enqueue_style('dpu-price-styles', plugin_dir_url(__FILE__) . 'dpu-price-styles.css', array(), '1.0.0');
        }
    }

    /**
     * Modify the price HTML display
     *
     * Hides the price for main products on single product pages,
     * but keeps it for products in loops (related, upsells, etc.)
     *
     * @param string $price_html The original price HTML
     * @param WC_Product $product The product object
     * @return string Modified price HTML
     */
    public function modify_price_html($price_html, $product)
    {
        // Don't modify price in admin
        if (is_admin()) {
            return $price_html;
        }

        global $post, $woocommerce_loop;

        // Check if this is the main product on a single product page
        $is_main_product = is_product()
            && is_singular('product')
            && isset($post)
            && $this->is_valid_product($product)
            && $product->get_id() === $post->ID;

        // Check if we're in a product loop (related products, upsells, etc.)
        $is_in_named_loop = isset($woocommerce_loop['name']) && !empty($woocommerce_loop['name']);
        $is_shortcode = isset($woocommerce_loop['is_shortcode']) && $woocommerce_loop['is_shortcode'];
        $is_in_loop = $is_in_named_loop || $is_shortcode || !$is_main_product;

        // Hide price only for the main product, not for products in loops
        if ($is_main_product && !$is_in_loop) {
            return ''; // Hide the normal price for main product
        }

        // Return original price HTML for all other cases
        return $price_html;
    }

    /**
     * Display custom price on single product page
     */
    public function display_custom_price()
    {
        global $product;
        if (!$this->is_valid_product($product) || !is_product()) {
            return;
        }

        $min_quantity = $this->get_product_min_quantity($product);
        $unit_price = $this->apply_tax_settings($product->get_price(), $product);
        $total_price = $unit_price * $min_quantity;

        $is_rozpyv = $this->is_valid_product($product) && has_term('rozpyv', 'product_cat', $product->get_id());

        $price_data = array(
            'unit_price' => $unit_price,
            'total_price' => $total_price
        );

        $enhanced_html = $this->generate_price_html($price_data, $min_quantity, $product);

        echo '<div class="dpu-price-container" data-product-id="' . $product->get_id() . '" data-unit-price="' . $unit_price . '" data-is-rozpyv="' . ($is_rozpyv ? '1' : '0') . '">' . $enhanced_html . '</div>';
    }

    /**
     * Check if the product is a valid WooCommerce product
     *
     * @param mixed $product The product to check
     * @return bool True if valid WC_Product
     */
    private function is_valid_product($product)
    {
        return $product instanceof WC_Product;
    }

    /**
     * Apply WooCommerce tax settings to price
     *
     * @param float $price The base price
     * @param WC_Product|null $product The product object
     * @return float Price with tax applied according to settings
     */
    private function apply_tax_settings($price, $product = null)
    {
        if (!wc_tax_enabled()) {
            return $price;
        }

        if ('incl' === get_option('woocommerce_tax_display_shop')) {
            return wc_get_price_including_tax($product, array('price' => $price));
        } else {
            return wc_get_price_excluding_tax($product, array('price' => $price));
        }
    }

    /**
     * Generate the HTML for price display
     *
     * @param array $price_data Array with unit_price and total_price
     * @param int $quantity The quantity
     * @param WC_Product|null $product The product object
     * @return string HTML for price display
     */
    private function generate_price_html($price_data, $quantity, $product = null)
    {
        $unit_text = $this->get_unit_text($product, $quantity);
        $total_price_text = sprintf('До кошика %d %s (%s грн)', $quantity, $unit_text, round($price_data['total_price']));

        $html = '<div class="dpu-price-wrapper">';
        $html .= '<div class="dpu-price-row">';
        $html .= '<div class="dpu-price-main">';
        $html .= '<span class="dpu-regular-price">' . wc_price($price_data['unit_price']) . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="dpu-price-row">';
        $html .= '<div class="dpu-total-price">' . $total_price_text . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the minimum quantity for a product
     *
     * @param WC_Product $product The product object
     * @return int Minimum quantity
     */
    private function get_product_min_quantity($product)
    {
        $min_quantity = 1;

        if ($this->is_valid_product($product)) {
            $args = apply_filters('woocommerce_quantity_input_args', array('min_value' => 1), $product);
            if (isset($args['min_value'])) {
                $min_quantity = max(1, (int) $args['min_value']);
            }
        }

        return $min_quantity;
    }

    /**
     * Get the unit text based on product category and quantity
     *
     * @param WC_Product|null $product The product object
     * @param int $quantity The quantity
     * @return string Unit text in Ukrainian
     */
    private function get_unit_text($product, $quantity)
    {
        $is_rozpyv = $product && $this->is_valid_product($product) && has_term('rozpyv', 'product_cat', $product->get_id());

        if ($is_rozpyv) {
            // Milliliter forms (Ukrainian grammar)
            if ($quantity == 1) {
                return 'мілілітр';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'мілілітри';
            } else {
                return 'мілілітрів';
            }
        } else {
            // Product forms (Ukrainian grammar)
            if ($quantity == 1) {
                return 'товар';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'товари';
            } else {
                return 'товарів';
            }
        }
    }
}

// Initialize the plugin
new Dynamic_Price_Updater();
