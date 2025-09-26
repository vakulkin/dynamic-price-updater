<?php

/**
 * Plugin Name: Динамічний оновлювач цін
 * Description: Просте відображення цін для товарів WooCommerce.
 * Version: 1.0.0
 * Text Domain: dynamic-price-updater
 */

if (! defined('ABSPATH')) {
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
        add_action('woocommerce_before_single_product', array( $this, 'enqueue_scripts' ));
        add_filter('woocommerce_get_price_html', array( $this, 'modify_price_html' ), 10, 2);
        add_action('woocommerce_single_product_summary', array( $this, 'display_custom_price' ), 15);
        add_filter('woocommerce_is_purchasable', array( $this, 'disable_zero_price_purchase' ), 10, 2);
    }

    /**
     * Disable purchase of products with zero price
     *
     * @param bool $is_purchasable Whether the product is purchasable
     * @param WC_Product $product The product object
     * @return bool Modified purchasable status
     */
    public function disable_zero_price_purchase($is_purchasable, $product)
    {
        if ($product->get_price() == 0) {
            return false;
        }
        return $is_purchasable;
    }

    public function enqueue_scripts()
    {
        global $product;
        if ($this->should_apply_custom_price($product)) {
            wp_enqueue_script('dynamic-price-updater', plugin_dir_url(__FILE__) . 'dynamic-price-updater.js', array( 'jquery' ), '1.0.0', true);
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
        $is_main_product = isset($post)
            && is_singular('product')
            && $product->get_id() === $post->ID
            && $this->should_apply_custom_price($product);

        // Check if we're in a product loop (related products, upsells, etc.)
        $is_in_named_loop = isset($woocommerce_loop['name']) && ! empty($woocommerce_loop['name']);
        $is_shortcode = isset($woocommerce_loop['is_shortcode']) && $woocommerce_loop['is_shortcode'];
        $is_in_loop = $is_in_named_loop || $is_shortcode || ! $is_main_product;

        // Hide price only for the main product, not for products in loops
        if ($is_main_product && ! $is_in_loop) {
            return ''; // Hide the normal price for main product
        }

        // If in loop and in rozpyv category, add /мл to price
        if ($is_in_loop && has_term('rozpyv', 'product_cat', $product->get_id())) {
            // Try to add /мл after the price value (before any HTML tags that may follow)
            // This works for most WooCommerce price formats
            $price_html = preg_replace('/(<span[^>]*class="[^"]*amount[^"]*"[^>]*>.*?<\/span>)/', '$1/мл', $price_html);
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
        if (! $this->should_apply_custom_price($product)) {
            return;
        }

        $min_quantity = $this->get_product_min_quantity($product);
        $regular_price = $this->apply_tax_settings($product->get_regular_price(), $product);
        $sale_price = $product->is_on_sale() ? $this->apply_tax_settings($product->get_sale_price(), $product) : null;
        $unit_price = $sale_price ?: $regular_price;
        $total_price = $unit_price * $min_quantity;

        $is_volume_based = $this->is_volume_based_product($product);
        $unit_type = $this->get_unit_type($product);

        $enhanced_html = $this->generate_price_html($regular_price, $sale_price, $total_price, $min_quantity, $product);

        echo '<div class="dpu-price-container" data-product-id="' . $product->get_id() . '" data-unit-price="' . $unit_price . '" data-is-volume-based="' . ($is_volume_based ? '1' : '0') . '" data-unit-type="' . $unit_type . '">' . $enhanced_html . '</div>';
    }

    /**
     * Check if custom price should be applied to the product
     *
     * @param mixed $product The product to check
     * @return bool True if custom price should be applied
     */
    private function should_apply_custom_price($product)
    {
        if (! $product instanceof WC_Product) {
            return false;
        }
        return $product->is_purchasable() && $product->is_in_stock();
    }

    /**
     * Check if the product is volume-based (uses milliliters instead of product count)
     *
     * @param WC_Product $product The product to check
     * @return bool True if volume-based
     */
    private function is_volume_based_product($product)
    {
        $volume_categories = array( 'rozpyv', 'povnoczinni-flakony', 'zalyshky' );
        foreach ($volume_categories as $category) {
            if (has_term($category, 'product_cat', $product->get_id())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the unit type for the product category
     *
     * @param WC_Product $product The product to check
     * @return string Unit type identifier
     */
    private function get_unit_type($product)
    {
        if (has_term('rozpyv', 'product_cat', $product->get_id())) {
            return 'milliliters';
        } elseif (has_term('povnoczinni-flakony', 'product_cat', $product->get_id())) {
            return 'full-bottles';
        } elseif (has_term('zalyshky', 'product_cat', $product->get_id())) {
            return 'remains-bottles';
        } else {
            return 'products';
        }
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
        if (! wc_tax_enabled()) {
            return $price;
        }

        if ('incl' === get_option('woocommerce_tax_display_shop')) {
            return wc_get_price_including_tax($product, array( 'price' => $price ));
        } else {
            return wc_get_price_excluding_tax($product, array( 'price' => $price ));
        }
    }

    /**
     * Generate the HTML for price display
     *
     * @param float $regular_price The regular unit price
     * @param float|null $sale_price The sale unit price if on sale
     * @param float $total_price The total price
     * @param int $quantity The quantity
     * @param WC_Product|null $product The product object
     * @return string HTML for price display
     */
    private function generate_price_html($regular_price, $sale_price, $total_price, $quantity, $product = null)
    {
        $unit_text = $this->get_unit_text($product, $quantity);
        $total_price_text = sprintf('До кошика %d %s (%s грн)', $quantity, $unit_text, round($total_price));

        $is_rozpyv = $product ? has_term('rozpyv', 'product_cat', $product->get_id()) : false;

        $html = '<div class="dpu-price-wrapper">';
        $html .= '<div class="dpu-price-row">';
        $html .= '<div class="dpu-price-main">';

        if ($sale_price !== null && $sale_price < $regular_price) {
            // Show crossed regular price first, then sale price
            $html .= '<span class="dpu-regular-price dpu-crossed">' . wc_price($regular_price) . ($is_rozpyv ? '/мл' : '') . '</span>';
            $html .= '<span class="dpu-sale-price">' . wc_price($sale_price) . ($is_rozpyv ? '/мл' : '') . '</span>';
        } else {
            // Show regular price only
            $html .= '<span class="dpu-regular-price">' . wc_price($regular_price) . ($is_rozpyv ? '/мл' : '') . '</span>';
        }

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

        $args = apply_filters('woocommerce_quantity_input_args', array( 'min_value' => $min_quantity ), $product);
        if (isset($args['min_value'])) {
            $min_quantity = max(1, (int) $args['min_value']);
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
        if (has_term('rozpyv', 'product_cat', $product->get_id())) {
            // Milliliter forms (Ukrainian grammar)
            if ($quantity == 1) {
                return 'мілілітр';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'мілілітри';
            } else {
                return 'мілілітрів';
            }
        } elseif (has_term('povnoczinni-flakony', 'product_cat', $product->get_id())) {
            // Full bottle forms (Ukrainian grammar)
            if ($quantity == 1) {
                return 'повноцінний флакон';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'повноцінні флакони';
            } else {
                return 'повноцінних флаконів';
            }
        } elseif (has_term('zalyshky', 'product_cat', $product->get_id())) {
            // Bottle with remains forms (Ukrainian grammar)
            if ($quantity == 1) {
                return 'флакон із залишками';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'флакони із залишками';
            } else {
                return 'флаконів із залишками';
            }
        } else {
            // Default product forms (Ukrainian grammar)
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
