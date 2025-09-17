<?php
/**
 * Plugin Name: Dynamic Price Updater
 * Description: Updates product price dynamically on single product page based on quantity for Woodmart dynamic discounts.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dynamic-price-updater
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dynamic_Price_Updater {

    public function __construct() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_discounted_price', array($this, 'get_discounted_price'));
        add_action('wp_ajax_nopriv_get_discounted_price', array($this, 'get_discounted_price'));
        add_action('woocommerce_single_product_summary', array($this, 'add_price_updater_script'), 30);
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 10, 2);
        add_action('woocommerce_single_product_summary', array($this, 'display_custom_price'), 15);
    }

    public function load_textdomain() {
        load_plugin_textdomain('dynamic-price-updater', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function enqueue_scripts() {
        if (is_product()) {
            global $product;

            // Get product ID safely
            $product_id = $this->get_product_id_safely($product);

            wp_enqueue_script('dynamic-price-updater', plugin_dir_url(__FILE__) . 'js/dynamic-price-updater.js', array('jquery'), '1.0.0', true);
            wp_localize_script('dynamic-price-updater', 'dpu_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dpu_nonce'),
                'product_id' => $product_id,
                'strings' => array(
                    'total' => __('Total', 'dynamic-price-updater'),
                    'for_items' => __('for %d items', 'dynamic-price-updater'),
                )
            ));

            // Add custom CSS for price display
            wp_enqueue_style('dpu-price-styles', plugin_dir_url(__FILE__) . 'css/dpu-price-styles.css', array(), '1.0.0');
        }
    }

    public function add_price_updater_script() {
        global $product;
        if (!$this->is_valid_product($product)) return;

        $discount = $this->get_discount_rules($product);
        if (empty($discount)) return;

        // Get the minimum quantity for this product
        $min_quantity = $this->get_product_min_quantity($product);

        echo '<script type="text/javascript">
            var dpu_discount_rules = ' . json_encode($discount) . ';
            var dpu_product_price = ' . $product->get_price() . ';
            var dpu_product_id = ' . $product->get_id() . ';
            var dpu_min_quantity = ' . $min_quantity . ';
        </script>';
    }

    public function modify_price_html($price_html, $product) {
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

    public function display_custom_price() {
        global $product;
        if (!$this->is_valid_product($product)) return;

        // Only show on single product pages
        if (!is_product()) return;

        // Check if this product has discount rules
        $discount = $this->get_discount_rules($product);
        if (empty($discount)) {
            // No discount rules, show regular price with total
            // Get minimum quantity for this product
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

        // Get minimum quantity for this product
        $min_quantity = $this->get_product_min_quantity($product);

        // Get current quantity (default to minimum quantity)
        $quantity = $min_quantity;
        if (isset($_POST['quantity'])) {
            $quantity = max($min_quantity, intval($_POST['quantity']));
        }

        // Calculate prices with discount
        $prices = $this->calculate_prices_with_discount($product, $quantity, $discount);

        // Generate enhanced price HTML
        $enhanced_html = $this->generate_price_html($prices, $quantity, $product);
        echo '<div class="dpu-price-container" data-product-id="' . $product->get_id() . '">' . $enhanced_html . '</div>';
    }

    public function get_discounted_price() {
        check_ajax_referer('dpu_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);

        $product = wc_get_product($product_id);
        if (!$this->is_valid_product($product)) {
            wp_send_json_error('Product not found');
        }

        $discount = $this->get_discount_rules($product);
        if (empty($discount)) {
            wp_send_json_error('No discount rules found');
        }

        if ($discount['_woodmart_rule_type'] !== 'bulk') {
            wp_send_json_error('Not a bulk discount type');
        }

        // Ensure quantity is at least the minimum
        $min_quantity = $this->get_product_min_quantity($product);
        $quantity = max($min_quantity, $quantity);

        // Calculate prices with discount
        $prices = $this->calculate_prices_with_discount($product, $quantity, $discount);

        // Determine unit text based on category and quantity
        $unit_text = $this->get_unit_text($product, $quantity);

        $price_data = array(
            'original_price' => wc_price($prices['original_price']),
            'unit_price' => wc_price($prices['discounted_price']),
            'total_price' => wc_price($prices['total_price']),
            'original_price_raw' => $prices['original_price'],
            'unit_price_raw' => $prices['discounted_price'],
            'total_price_raw' => $prices['total_price'],
            'has_discount' => $prices['has_discount'],
            'quantity' => $quantity,
            'unit_text' => $unit_text
        );

        error_log('DPU: Price data - Original: ' . $price_data['original_price'] . ', Unit: ' . $price_data['unit_price'] . ', Total: ' . $price_data['total_price'] . ', Has discount: ' . ($prices['has_discount'] ? 'yes' : 'no') . ', Quantity: ' . $quantity);
        wp_send_json_success($price_data);
    }

    private function get_discount_rules($product) {
        if (!$this->is_valid_product($product)) {
            return array();
        }

        $all_discount_rules = $this->get_all_discount_rules();

        if (!is_array($all_discount_rules)) {
            return array();
        }

        uasort($all_discount_rules, array($this, 'sort_by_priority'));

        foreach ($all_discount_rules as $discounts_id => $discount_rules) {
            if (!$this->check_discount_condition($discount_rules, $product)) {
                continue;
            }

            $discount_rules['post_id'] = $discounts_id;
            $discount_rules['title'] = get_the_title($discounts_id);

            return $discount_rules;
        }

        return array();
    }

    private function get_all_discount_rules() {
        $cache_key = 'dpu_all_discount_rules';
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $discount_posts = get_posts(array(
            'post_type' => 'wd_woo_discounts',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));

        if (empty($discount_posts)) {
            return array();
        }

        $rules = array();
        foreach ($discount_posts as $post) {
            $rules[$post->ID] = $this->get_single_discount_rules($post->ID);
        }

        set_transient($cache_key, $rules, HOUR_IN_SECONDS);
        return $rules;
    }

    private function get_single_discount_rules($post_id) {
        $meta_keys = array(
            '_woodmart_rule_type',
            'discount_condition',
            'discount_rules',
            'discount_quantities'
        );

        $rules = array();
        foreach ($meta_keys as $key) {
            $rules[$key] = get_post_meta($post_id, $key, true);
        }

        return $rules;
    }

    private function check_discount_condition($discount_rules, $product) {
        if (empty($discount_rules['discount_condition']) || !is_array($discount_rules['discount_condition'])) {
            return false;
        }

        if (!$this->is_valid_product($product)) {
            return false;
        }

        $conditions = $discount_rules['discount_condition'];
        $is_active = false;
        $is_exclude = false;

        if ('variation' === $product->get_type()) {
            $product = wc_get_product($product->get_parent_id());
        }

        foreach ($conditions as $condition) {
            $condition['woodmart_discount_priority'] = $this->get_condition_priority($condition['type']);

            switch ($condition['type']) {
                case 'all':
                    $is_active = 'include' === $condition['comparison'];
                    if ('exclude' === $condition['comparison']) {
                        $is_exclude = true;
                    }
                    break;
                case 'product':
                    $is_needed_product = (int) $product->get_id() === (int) $condition['query'];
                    if ($is_needed_product) {
                        if ('exclude' === $condition['comparison']) {
                            $is_active = false;
                            $is_exclude = true;
                        } else {
                            $is_active = true;
                        }
                    }
                    break;
                case 'product_type':
                    $is_needed_type = $product->get_type() === $condition['product-type-query'];
                    if ($is_needed_type) {
                        if ('exclude' === $condition['comparison']) {
                            $is_active = false;
                            $is_exclude = true;
                        } else {
                            $is_active = true;
                        }
                    }
                    break;
                case 'product_cat':
                case 'product_tag':
                    $terms = wp_get_post_terms($product->get_id(), $condition['type'], array('fields' => 'ids'));
                    if ($terms) {
                        $is_needed_term = in_array((int) $condition['query'], $terms, true);
                        if ($is_needed_term) {
                            if ('exclude' === $condition['comparison']) {
                                $is_active = false;
                                $is_exclude = true;
                            } else {
                                $is_active = true;
                            }
                        }
                    }
                    break;
            }

            if ($is_exclude || $is_active) {
                break;
            }
        }

        return $is_active;
    }

    private function get_condition_priority($type) {
        $priority = 50;
        switch ($type) {
            case 'all': $priority = 10; break;
            case 'product_type':
            case 'product_cat':
            case 'product_tag': $priority = 30; break;
            case 'product': $priority = 40; break;
        }
        return $priority;
    }

    private function sort_by_priority($a, $b) {
        return $b['woodmart_discount_priority'] <=> $a['woodmart_discount_priority'];
    }

    private function is_valid_product($product) {
        return $product && is_a($product, 'WC_Product');
    }

    private function apply_tax_settings($price, $product = null) {
        if (!wc_tax_enabled()) {
            return $price;
        }

        if ('incl' === get_option('woocommerce_tax_display_shop')) {
            return wc_get_price_including_tax($product, array('price' => $price));
        } else {
            return wc_get_price_excluding_tax($product, array('price' => $price));
        }
    }

    private function calculate_prices_with_discount($product, $quantity, $discount) {
        $original_price = $product->get_price();
        $discounted_price = $original_price;
        $has_discount = false;

        foreach ($discount['discount_rules'] as $rule) {
            if ($rule['_woodmart_discount_rules_from'] <= $quantity &&
                ($quantity <= $rule['_woodmart_discount_rules_to'] || empty($rule['_woodmart_discount_rules_to']))) {

                $discount_type = $rule['_woodmart_discount_type'];
                $discount_value = $rule['_woodmart_discount_' . $discount_type . '_value'];

                $discounted_price = $this->calculate_discounted_price($original_price, $discount_type, $discount_value);
                $has_discount = true;
                break;
            }
        }

        // Apply tax settings
        $original_price = $this->apply_tax_settings($original_price, $product);
        $discounted_price = $this->apply_tax_settings($discounted_price, $product);

        return array(
            'original_price' => $original_price,
            'discounted_price' => $discounted_price,
            'has_discount' => $has_discount,
            'total_price' => $discounted_price * $quantity
        );
    }

    private function generate_price_html($prices, $quantity, $product = null) {
        // Determine the unit text based on product category and quantity
        $unit_text = $this->get_unit_text($product, $quantity);

        $total_price_text = 'До кошика ' . $quantity . ' ' . $unit_text . ' (' . round($prices['total_price']) . ' грн)';

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
    private function get_product_min_quantity($product) {
        $min_quantity = 1;

        // Try to get min quantity from justb2b-options plugin using WooCommerce filter
        if ($this->is_valid_product($product)) {
            $args = apply_filters('woocommerce_quantity_input_args', array('min_value' => 1), $product);
            if (isset($args['min_value'])) {
                $min_quantity = max(1, (int) $args['min_value']);
                return $min_quantity;
            }
        }

        // Fallback to wcmmq plugin if justb2b-options is not available or doesn't set min_value
        if (function_exists('wcmmq_get_product_limits')) {
            $limits = wcmmq_get_product_limits($product->get_id());
            if (is_array($limits) && isset($limits['min_qty'])) {
                $min_quantity = max(1, (int) $limits['min_qty']);
            }
        }

        return $min_quantity;
    }

    private function get_product_id_safely($product) {
        if ($product && is_a($product, 'WC_Product')) {
            return $product->get_id();
        }
        return get_the_ID() ?: 0;
    }

    private function get_unit_text($product, $quantity) {
        $is_rozpyv = $product && $this->is_valid_product($product) && has_term('rozpyv', 'product_cat', $product->get_id());
        
        if ($is_rozpyv) {
            // Ukrainian plural forms for "мілілітр"
            if ($quantity == 1) {
                return 'мілілітр';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'мілілітри';
            } else {
                return 'мілілітрів';
            }
        } else {
            // Ukrainian plural forms for "товар"
            if ($quantity == 1) {
                return 'товар';
            } elseif ($quantity >= 2 && $quantity <= 4) {
                return 'товари';
            } else {
                return 'товарів';
            }
        }
    }

    private function calculate_discounted_price($price, $type, $value) {
        if ($type === 'amount') {
            return $price - $value;
        } elseif ($type === 'percentage') {
            return $price - ($price * ($value / 100));
        }
        return $price;
    }
}

new Dynamic_Price_Updater();
