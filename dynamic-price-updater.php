<?php

/**
 * Plugin Name: Dynamic Price Updater
 * Description: Updates product price dynamically on single product page based on quantity for Woodmart dynamic discounts.
 * Version: 1.0.0
 * Text Domain: dynamic-price-updater
 */

if (!defined('ABSPATH')) {
    exit;
}


// Add direct translation overrides as fallback
add_filter('gettext', 'dpu_override_translations', 10, 3);
add_filter('gettext_with_context', 'dpu_override_translations', 10, 4);

function dpu_override_translations($translation, $text, $domain, $context = '')
{
    if ($domain === 'dynamic-price-updater') {
        $overrides = array(
            'Add to cart %d %s (%s UAH)' => 'До кошика %d %s (%s грн)',
            'Add to cart' => 'До кошика',
            'UAH' => 'грн',
            'Total' => 'Загалом',
            'for %d items' => 'за %d товарів',
            'milliliter' => 'мілілітр',
            'milliliters' => 'мілілітри',
            'milliliters_gen' => 'мілілітрів',
            'item' => 'товар',
            'items' => 'товари',
            'items_gen' => 'товарів',
            'Dynamic Price Updater' => 'Динамічний оновлювач цін',
            'Requires Woodmart theme with Dynamic Discounts enabled.' => 'Потребує тему Woodmart з увімкненими динамічними знижками.'
        );

        if (isset($overrides[$text])) {
            return $overrides[$text];
        }
    }

    return $translation;
}

// Check if Woodmart is active and dynamic discounts are enabled
add_action('init', function () {
    // Simplified dependency check
    if (!function_exists('woodmart_woocommerce_installed') ||
        !woodmart_woocommerce_installed() ||
        !function_exists('woodmart_get_opt') ||
        !woodmart_get_opt('discounts_enabled', 0) ||
        !class_exists('XTS\Modules\Dynamic_Discounts\Manager') ||
        !class_exists('XTS\Modules\Dynamic_Discounts\Main')) {

        add_action('admin_init', function () {
            add_action('admin_notices', function () {
                echo '<div class="error"><p><strong>' . __('Dynamic Price Updater', 'dynamic-price-updater') . ':</strong> ' . __('Requires Woodmart theme with Dynamic Discounts enabled.', 'dynamic-price-updater') . '</p></div>';
            });
        });
        return;
    }

    new Dynamic_Price_Updater();
});

class Dynamic_Price_Updater
{
    private $woodmart_manager;
    private $woodmart_main;

    public function __construct()
    {
        // Initialize Woodmart classes directly since they're required
        $this->woodmart_manager = \XTS\Modules\Dynamic_Discounts\Manager::get_instance();
        $this->woodmart_main = \XTS\Modules\Dynamic_Discounts\Main::get_instance();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_discounted_price', array($this, 'get_discounted_price'));
        add_action('wp_ajax_nopriv_get_discounted_price', array($this, 'get_discounted_price'));
        add_action('woocommerce_single_product_summary', array($this, 'add_price_updater_script'), 30);
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 10, 2);
        add_action('woocommerce_single_product_summary', array($this, 'display_custom_price'), 15);
    }

    public function enqueue_scripts()
    {
        if (is_product()) {
            global $product;

            // Ensure we have a valid product object
            if (!$this->is_valid_product($product)) {
                $product = wc_get_product(get_the_ID());
            }

            // Get product ID safely
            $product_id = $this->get_product_id_safely($product);

            wp_enqueue_script('dynamic-price-updater', plugin_dir_url(__FILE__) . 'js/dynamic-price-updater.js', array('jquery'), '1.0.0', true);
            wp_localize_script('dynamic-price-updater', 'dpu_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dpu_nonce'),
                'product_id' => $product_id,
                'min_quantity' => $this->get_product_min_quantity($product),
                'strings' => array(
                    'add_to_cart' => __('Add to cart', 'dynamic-price-updater'),
                    'for_items' => __('for %d items', 'dynamic-price-updater'),
                    'currency' => __('UAH', 'dynamic-price-updater'),
                )
            ));

            // Add custom CSS for price display
            wp_enqueue_style('dpu-price-styles', plugin_dir_url(__FILE__) . 'css/dpu-price-styles.css', array(), '1.0.0');
        }
    }

    public function add_price_updater_script()
    {
        global $product;
        if (!$this->is_valid_product($product)) {
            return;
        }

        $discount = $this->get_discount_rules($product);
        if (empty($discount)) {
            return;
        }

        // Get the minimum quantity for this product
        $min_quantity = $this->get_product_min_quantity($product);

        // No longer need to inject JavaScript variables - AJAX handles everything
        // The product_id comes from wp_localize_script in enqueue_scripts()
    }

    public function modify_price_html($price_html, $product)
    {
        // Don't modify price in admin
        if (is_admin()) {
            return $price_html;
        }

        global $post, $woocommerce_loop;

        // Check if this is the main product on a single product page
        $isMainProduct = is_product()
            && is_singular('product')
            && isset($post)
            && $this->is_valid_product($product)
            && $product->get_id() === $post->ID;

        // Check if we're in a product loop (related products, upsells, etc.)
        $isInNamedLoop = isset($woocommerce_loop['name']) && !empty($woocommerce_loop['name']);
        $isShortcode = isset($woocommerce_loop['is_shortcode']) && $woocommerce_loop['is_shortcode'];
        $isInLoop = $isInNamedLoop || $isShortcode || !$isMainProduct;

        // Hide price only for the main product, not for products in loops
        if ($isMainProduct && !$isInLoop) {
            return ''; // Hide the normal price for main product
        }

        // Return original price HTML for all other cases
        return $price_html;
    }

    public function display_custom_price()
    {
        global $product;
        if (!$this->is_valid_product($product) || !is_product()) {
            return;
        }

        $discount = $this->get_discount_rules($product);
        if (empty($discount)) {
            // No discount rules, show regular price
            $min_quantity = $this->get_product_min_quantity($product);
            $price = $this->apply_tax_settings($product->get_price(), $product);
            $total_price = $price * $min_quantity;

            $prices = array(
                'original_price' => $price,
                'discounted_price' => $price,
                'has_discount' => false,
                'total_price' => $total_price
            );

            $enhanced_html = $this->generate_price_html($prices, $min_quantity, $product);
            echo '<div class="dpu-price-container" data-product-id="' . $product->get_id() . '">' . $enhanced_html . '</div>';
            return;
        }

        // Process discount rules
        $min_quantity = $this->get_product_min_quantity($product);
        $quantity = isset($_POST['quantity']) ? max($min_quantity, intval($_POST['quantity'])) : $min_quantity;
        $prices = $this->calculate_prices_with_discount($product, $quantity, $discount);
        $enhanced_html = $this->generate_price_html($prices, $quantity, $product);
        echo '<div class="dpu-price-container" data-product-id="' . $product->get_id() . '">' . $enhanced_html . '</div>';
    }

    public function get_discounted_price()
    {
        check_ajax_referer('dpu_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);

        $product = wc_get_product($product_id);
        if (!$this->is_valid_product($product)) {
            wp_send_json_error('Product not found');
        }

        $discount = $this->get_discount_rules($product);
        if (empty($discount) || $discount['_woodmart_rule_type'] !== 'bulk') {
            wp_send_json_error('No valid discount rules found');
        }

        $min_quantity = $this->get_product_min_quantity($product);
        $quantity = max($min_quantity, $quantity);
        $prices = $this->calculate_prices_with_discount($product, $quantity, $discount);
        $unit_text = $this->get_unit_text($product, $quantity);

        wp_send_json_success(array(
            'original_price' => wc_price($prices['original_price']),
            'unit_price' => wc_price($prices['discounted_price']),
            'total_price' => wc_price($prices['total_price']),
            'original_price_raw' => $prices['original_price'],
            'unit_price_raw' => $prices['discounted_price'],
            'total_price_raw' => $prices['total_price'],
            'has_discount' => $prices['has_discount'],
            'quantity' => $quantity,
            'unit_text' => $unit_text
        ));
    }

    private function get_discount_rules($product)
    {
        if (!$this->is_valid_product($product)) {
            return array();
        }

        try {
            return $this->woodmart_manager->get_discount_rules($product);
        } catch (Exception $e) {
            return array();
        }
    }

    private function is_valid_product($product)
    {
        return $product instanceof WC_Product;
    }

    private function apply_tax_settings($price, $product = null)
    {
        if (!wc_tax_enabled()) {
            return $price;
        }

        if ('incl' === get_option('effect loo')) {
            return wc_get_price_including_tax($product, array('price' => $price));
        } else {
            return wc_get_price_excluding_tax($product, array('price' => $price));
        }
    }

    private function calculate_prices_with_discount($product, $quantity, $discount)
    {
        $original_price = $product->get_price();
        $discounted_price = $original_price;
        $has_discount = false;

        foreach ($discount['discount_rules'] as $rule) {
            if ($rule['_woodmart_discount_rules_from'] <= $quantity &&
                ($quantity <= $rule['_woodmart_discount_rules_to'] || empty($rule['_woodmart_discount_rules_to']))) {

                $discount_type = $rule['_woodmart_discount_type'];
                $discount_value = $rule['_woodmart_discount_' . $discount_type . '_value'];

                try {
                    $discounted_price = $this->woodmart_main->get_product_price($original_price, array(
                        'type' => $discount_type,
                        'value' => $discount_value
                    ));
                    $has_discount = true;
                    break;
                } catch (Exception $e) {
                    break;
                }
            }
        }

        $original_price = $this->apply_tax_settings($original_price, $product);
        $discounted_price = $this->apply_tax_settings($discounted_price, $product);

        return array(
            'original_price' => $original_price,
            'discounted_price' => $discounted_price,
            'has_discount' => $has_discount,
            'total_price' => $discounted_price * $quantity
        );
    }

    private function generate_price_html($prices, $quantity, $product = null)
    {

        $unit_text = $this->get_unit_text($product, $quantity);
        $total_price_text = sprintf(__('Add to cart %d %s (%s UAH)', 'dynamic-price-updater'), $quantity, $unit_text, round($prices['total_price']));

        if ($prices['has_discount']) {
            $savings_percentage = round((($prices['original_price'] - $prices['discounted_price']) / $prices['original_price']) * 100);
            return '<div class="dpu-price-wrapper">' .
                '<div class="dpu-price-row">' .
                    '<div class="dpu-price-main">' .
                        '<del class="dpu-original-price">' . wc_price($prices['original_price']) . '</del>' .
                        '<span class="dpu-discounted-price">' . wc_price($prices['discounted_price']) . '</span>' .
                    '</div>' .
                    '<div class="dpu-savings-badge">-' . $savings_percentage . '%</div>' .
                '</div>' .
                '<div class="dpu-price-row">' .
                    '<div class="dpu-total-price">' . $total_price_text . '</div>' .
                '</div>' .
                '</div>';
        } else {
            return '<div class="dpu-price-wrapper">' .
                '<div class="dpu-price-row">' .
                    '<div class="dpu-price-main">' .
                        '<span class="dpu-regular-price">' . wc_price($prices['discounted_price']) . '</span>' .
                    '</div>' .
                '</div>' .
                '<div class="dpu-price-row">' .
                    '<div class="dpu-total-price">' . $total_price_text . '</div>' .
                '</div>' .
                '</div>';
        }
    }
    private function get_product_min_quantity($product)
    {
        $min_quantity = 1;

        if ($this->is_valid_product($product)) {
            $args = apply_filters('woocommerce_quantity_input_args', array('min_value' => 1), $product);
            if (isset($args['min_value'])) {
                $min_quantity = max(1, (int) $args['min_value']);
                return $min_quantity;
            }
        }

        if (function_exists('wcmmq_get_product_limits') && $this->is_valid_product($product)) {
            $limits = wcmmq_get_product_limits($product->get_id());
            if (is_array($limits) && isset($limits['min_qty'])) {
                $min_quantity = max(1, (int) $limits['min_qty']);
            }
        }

        return $min_quantity;
    }

    private function get_product_id_safely($product)
    {
        return $product instanceof WC_Product ? $product->get_id() : (get_the_ID() ?: 0);
    }

    private function get_unit_text($product, $quantity)
    {
        $is_rozpyv = $product && $this->is_valid_product($product) && has_term('rozpyv', 'product_cat', $product->get_id());

        if ($is_rozpyv) {
            // Milliliter forms
            if ($quantity == 1) {
                return __('milliliter', 'dynamic-price-updater');
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return __('milliliters', 'dynamic-price-updater');
            } else {
                return __('milliliters_gen', 'dynamic-price-updater');
            }
        } else {
            // Product forms
            if ($quantity == 1) {
                return __('item', 'dynamic-price-updater');
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return __('items', 'dynamic-price-updater');
            } else {
                return __('items_gen', 'dynamic-price-updater');
            }
        }
    }
}
