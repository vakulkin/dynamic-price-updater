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
            this.$qtyInput = $('input.qty');
            this.$priceContainer = $('.dpu-price-container');
            this.animationDuration = 300;
            this.updateDelay = 100;

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
                setTimeout(() => {
                    this.updateTotalPrice();
                }, this.updateDelay);
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
            const isRozpyv = this.isRozpyvProduct();

            const unitText = this.getUnitText(quantity, isRozpyv);
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
            return parseFloat(this.$priceContainer.data('unit-price')) || 0;
        }

        /**
         * Check if this is a rozpyv (liquid) product
         * @return {boolean} True if rozpyv product
         */
        isRozpyvProduct() {
            return this.$priceContainer.data('is-rozpyv') === '1';
        }

        /**
         * Get the appropriate unit text based on quantity and product type
         * @param {number} quantity - The quantity
         * @param {boolean} isRozpyv - Whether it's a rozpyv product
         * @return {string} Unit text in Ukrainian
         */
        getUnitText(quantity, isRozpyv) {
            if (isRozpyv) {
                return this.getMilliliterText(quantity);
            } else {
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

            // Add click animation
            this.animateButtonClick($button);

            // Find and trigger the real WooCommerce add to cart button
            this.triggerAddToCart(quantity);
        }

        /**
         * Animate the button click
         * @param {jQuery} $button - The button element
         */
        animateButtonClick($button) {
            $button.addClass('dpu-clicked');
            setTimeout(() => {
                $button.removeClass('dpu-clicked');
            }, this.animationDuration);
        }

        /**
         * Trigger the WooCommerce add to cart functionality
         * @param {number} quantity - The quantity to add
         */
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
