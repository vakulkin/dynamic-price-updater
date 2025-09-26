/**
 * Dynamic Price Updater - JavaScript functionality
 *
 * Handles dynamic price updates based on quantity changes and
 * provides interactive add-to-cart functionality.
 */

(function($) {
    'use strict';

    /**
     * Dynamic Price Updater Class
     */
    class DynamicPriceUpdater {
        constructor() {
            this.$qtyInput = $('form.cart input.qty');
            this.$priceContainer = $('.dpu-price-container');

            this.init();
        }

        /**
         * Initialize the price updater
         */
        init() {
            if (this.$qtyInput.length === 0 || this.$priceContainer.length === 0) {
                return;
            }

            this.bindEvents();
        }

        /**
         * Bind all necessary events
         */
        bindEvents() {
            // Update price on quantity input change
            this.$qtyInput.on('input change', () => {
                this.updateTotalPrice();
            });

            // Handle plus/minus button clicks
            $(document).on('click', '.plus, .minus', () => {
                this.updateTotalPrice();
            });

            // Handle add to cart button click
            $(document).on('click', '.dpu-total-price', (e) => {
                this.handleAddToCart(e);
            });
        }

        /**
         * Update the total price display
         */
        updateTotalPrice() {
            const quantity = this.getCurrentQuantity();
            const unitPrice = this.getUnitPrice();
            const totalPrice = unitPrice * quantity;
            const unitType = this.getUnitType();

            const unitText = this.getUnitText(quantity, unitType);
            const totalPriceText = this.formatTotalPriceText(quantity, unitText, totalPrice);

            this.updatePriceDisplay(totalPriceText);
        }

        /**
         * Get the current quantity from input
         * @return {number} Current quantity
         */
        getCurrentQuantity() {
            return parseInt(this.$qtyInput.val()) || 1;
        }

        /**
         * Get the unit price from data attribute
         * @return {number} Unit price
         */
        getUnitPrice() {
            return parseFloat(this.$priceContainer.attr('data-unit-price')) || 0;
        }

        /**
         * Get the unit type from data attribute
         * @return {string} Unit type
         */
        getUnitType() {
            return this.$priceContainer.attr('data-unit-type') || 'products';
        }

        /**
         * Check if this is a volume-based (liquid) product
         * @return {boolean} True if volume-based product
         */
        isVolumeBasedProduct() {
            return this.$priceContainer.attr('data-is-volume-based') === '1';
        }

        /**
         * Get the appropriate unit text based on quantity and unit type
         * @param {number} quantity - The quantity
         * @param {string} unitType - The unit type ('milliliters', 'full-bottles', 'remains-bottles', 'products')
         * @return {string} Unit text in Ukrainian
         */
        getUnitText(quantity, unitType) {
            switch (unitType) {
                case 'milliliters':
                    return this.getMilliliterText(quantity);
                case 'full-bottles':
                    return this.getFullBottleText(quantity);
                case 'remains-bottles':
                    return this.getRemainsBottleText(quantity);
                default:
                    return this.getProductText(quantity);
            }
        }

        /**
         * Get milliliter text based on Ukrainian grammar rules
         * @param {number} quantity - The quantity
         * @return {string} Milliliter text
         */
        getMilliliterText(quantity) {
            if (quantity === 1) {
                return 'мілілітр';
            } else if (quantity >= 2 && quantity <= 4) {
                return 'мілілітри';
            } else {
                return 'мілілітрів';
            }
        }

        /**
         * Get product text based on Ukrainian grammar rules
         * @param {number} quantity - The quantity
         * @return {string} Product text
         */
        getProductText(quantity) {
            if (quantity === 1) {
                return 'товар';
            } else if (quantity >= 2 && quantity <= 4) {
                return 'товари';
            } else {
                return 'товарів';
            }
        }

        /**
         * Get full bottle text based on Ukrainian grammar rules
         * @param {number} quantity - The quantity
         * @return {string} Full bottle text
         */
        getFullBottleText(quantity) {
            if (quantity === 1) {
                return 'повноцінний флакон';
            } else if (quantity >= 2 && quantity <= 4) {
                return 'повноцінні флакони';
            } else {
                return 'повноцінних флаконів';
            }
        }

        /**
         * Get remains bottle text based on Ukrainian grammar rules
         * @param {number} quantity - The quantity
         * @return {string} Remains bottle text
         */
        getRemainsBottleText(quantity) {
            if (quantity === 1) {
                return 'флакон із залишками';
            } else if (quantity >= 2 && quantity <= 4) {
                return 'флакони із залишками';
            } else {
                return 'флаконів із залишками';
            }
        }

        /**
         * Format the total price text
         * @param {number} quantity - The quantity
         * @param {string} unitText - The unit text
         * @param {number} totalPrice - The total price
         * @return {string} Formatted price text
         */
        formatTotalPriceText(quantity, unitText, totalPrice) {
            return `До кошика ${quantity} ${unitText} (${Math.round(totalPrice)} грн)`;
        }

        /**
         * Update the price display element
         * @param {string} text - The new text to display
         */
        updatePriceDisplay(text) {
            const $totalPriceElement = this.$priceContainer.find('.dpu-total-price');
            if ($totalPriceElement.length > 0) {
                $totalPriceElement.text(text);
            }
        }

        /**
         * Handle add to cart button click
         * @param {Event} e - The click event
         */
        handleAddToCart(e) {
            e.preventDefault();

            const $button = $(e.target).closest('.dpu-total-price');
            const quantity = this.getCurrentQuantity();

            // Find and trigger the real WooCommerce add to cart button
            this.triggerAddToCart(quantity);
        }

        triggerAddToCart(quantity) {
            // Set the quantity first
            this.$qtyInput.val(quantity);

            // Find and click the real WooCommerce add to cart button
            const $addToCartButton = $('button[name="add-to-cart"], .single_add_to_cart_button, input[name="add-to-cart"]');

            if ($addToCartButton.length > 0) {
                $addToCartButton.first().trigger('click');
            }
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new DynamicPriceUpdater();
    });

})(jQuery);
