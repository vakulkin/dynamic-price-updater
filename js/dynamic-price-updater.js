jQuery(document).ready(function($) {
    // Check if we're on a single product page
    if (typeof dpu_ajax === 'undefined' || !dpu_ajax.product_id) {
       // Also listen for plus/minus button clicks if they exist
    $(document).on('click', '.plus, .minus', function() {
        setTimeout(function() {
            var quantity = parseInt($qtyInput.val()) || (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity ? dpu_ajax.min_quantity : 1);

            // Ensure quantity doesn't go below minimum
            if (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity && quantity < dpu_ajax.min_quantity) {
                quantity = dpu_ajax.min_quantity;
                $qtyInput.val(quantity);
            }

            updatePrice(quantity);
        }, 100);
    });   }

    var $qtyInput = $('input.qty');
    var $priceContainer = $('.dpu-price-container[data-product-id="' + dpu_ajax.product_id + '"]');

    if ($qtyInput.length === 0 || $priceContainer.length === 0) {
        return;
    }

    function updatePrice(quantity) {
        // Add loading class immediately
        $priceContainer.addClass('dpu-price-loading');

        // Send AJAX request to get formatted price
        $.ajax({
            url: dpu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_discounted_price',
                product_id: dpu_ajax.product_id,
                quantity: quantity,
                nonce: dpu_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var priceData = response.data;

                    // Add loading class
                    $priceContainer.addClass('dpu-price-loading');

                    var priceHtml = '';

                    if (priceData.has_discount) {
                        // Use raw prices for calculation, formatted prices for display
                        var originalPriceNum = parseFloat(priceData.original_price_raw || priceData.original_price.replace(/[^\d.,]/g, '').replace(',', '.'));
                        var unitPriceNum = parseFloat(priceData.unit_price_raw || priceData.unit_price.replace(/[^\d.,]/g, '').replace(',', '.'));
                        var savingsPercentage = 0;

                        if (originalPriceNum > 0 && unitPriceNum > 0 && originalPriceNum > unitPriceNum) {
                            savingsPercentage = Math.round(((originalPriceNum - unitPriceNum) / originalPriceNum) * 100);
                        }

                        // Use prices as-is (WooCommerce already formats them safely)
                        var displayOriginalPrice = priceData.original_price;
                        var displayUnitPrice = priceData.unit_price;
                        var displayTotalPrice = priceData.total_price;

                        // Get translation strings safely
                        var totalText = dpu_ajax.strings && dpu_ajax.strings.add_to_cart ? dpu_ajax.strings.add_to_cart : 'До кошика';
                        var unitText = priceData.unit_text || 'товарів';
                        var currencyText = dpu_ajax.strings && dpu_ajax.strings.currency ? dpu_ajax.strings.currency : 'грн';
                        var forItemsText = dpu_ajax.strings && dpu_ajax.strings.for_items ? dpu_ajax.strings.for_items.replace('%d', priceData.quantity) : 'для ' + priceData.quantity + ' ' + unitText;

                        priceHtml = '<div class="dpu-price-wrapper dpu-price-updated" data-total-price="' + displayTotalPrice.replace(/"/g, '&quot;') + '">' +
                            '<div class="dpu-price-row">' +
                                '<div class="dpu-price-main">' +
                                    '<del class="dpu-original-price">' + displayOriginalPrice + '</del>' +
                                    '<span class="dpu-discounted-price">' + displayUnitPrice + '</span>' +
                                '</div>' +
                                '<div class="dpu-savings-badge">-' + savingsPercentage + '%</div>' +
                            '</div>' +
                            '<div class="dpu-price-row">' +
                                '<div class="dpu-total-price">' + totalText + ' ' + priceData.quantity + ' ' + unitText + ' (' + Math.round(priceData.total_price_raw) + ' ' + currencyText + ')</div>' +
                            '</div>' +
                            '</div>';
                    } else {
                        // Use prices as-is (WooCommerce already formats them safely)
                        var displayUnitPrice = priceData.unit_price;
                        var displayTotalPrice = priceData.total_price;

                        // Get translation strings safely
                        var totalText = dpu_ajax.strings && dpu_ajax.strings.add_to_cart ? dpu_ajax.strings.add_to_cart : 'До кошика';
                        var unitText = priceData.unit_text || 'товарів';
                        var currencyText = dpu_ajax.strings && dpu_ajax.strings.currency ? dpu_ajax.strings.currency : 'грн';
                        var forItemsText = dpu_ajax.strings && dpu_ajax.strings.for_items ? dpu_ajax.strings.for_items.replace('%d', priceData.quantity) : 'для ' + priceData.quantity + ' ' + unitText;

                        priceHtml = '<div class="dpu-price-wrapper dpu-price-updated" data-total-price="' + displayTotalPrice.replace(/"/g, '&quot;') + '">' +
                            '<div class="dpu-price-row">' +
                                '<div class="dpu-price-main">' +
                                    '<span class="dpu-regular-price">' + displayUnitPrice + '</span>' +
                                '</div>' +
                            '</div>' +
                            '<div class="dpu-price-row">' +
                                '<div class="dpu-total-price">' + totalText + ' ' + priceData.quantity + ' ' + unitText + ' (' + Math.round(priceData.total_price_raw) + ' ' + currencyText + ')</div>' +
                            '</div>' +
                            '</div>';
                    }

                    $priceContainer.html(priceHtml);

                    // Remove loading class after a short delay
                    setTimeout(function() {
                        $priceContainer.removeClass('dpu-price-loading');
                    }, 300);
                } else {
                    // Handle unsuccessful response silently
                }
            },
            error: function(xhr, status, error) {
                // Handle AJAX errors silently
            }
        });
    }

    // Update price on page load for current quantity
    var initialQuantity = parseInt($qtyInput.val()) || (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity ? dpu_ajax.min_quantity : 1);

    // Ensure initial quantity is at least the minimum (no AJAX needed on first load)
    if (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity && initialQuantity < dpu_ajax.min_quantity) {
        initialQuantity = dpu_ajax.min_quantity;
        $qtyInput.val(initialQuantity);
    }

    // No AJAX call needed on first load - price is already correct

    // Listen for quantity changes
    $qtyInput.on('input change', function() {
        var quantity = parseInt($(this).val()) || (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity ? dpu_ajax.min_quantity : 1);

            // Ensure quantity doesn't go below minimum
            if (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity && quantity < dpu_ajax.min_quantity) {
                quantity = dpu_ajax.min_quantity;
                $qtyInput.val(quantity);
            }        updatePrice(quantity);
    });

    // Also listen for plus/minus button clicks if they exist
    $(document).on('click', '.plus, .minus', function() {
        setTimeout(function() {
            var quantity = parseInt($qtyInput.val()) || (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity ? dpu_ajax.min_quantity : 1);

            // Ensure quantity doesn't go below minimum
            if (typeof dpu_ajax !== 'undefined' && dpu_ajax.min_quantity && quantity < dpu_ajax.min_quantity) {
                quantity = dpu_ajax.min_quantity;
                $qtyInput.val(quantity);
            }

            updatePrice(quantity);
        }, 100);
    });

    // Handle add to cart functionality for the total price badge
    $(document).on('click', '.dpu-total-price', function(e) {
        e.preventDefault();

        var $button = $(this);
        var quantity = parseInt($qtyInput.val()) || 1;

        // Add click animation
        $button.addClass('dpu-clicked');
        setTimeout(function() {
            $button.removeClass('dpu-clicked');
        }, 300);

        // Find and click the real WooCommerce add to cart button
        var $addToCartButton = $('button[name="add-to-cart"], .single_add_to_cart_button, input[name="add-to-cart"]');

        if ($addToCartButton.length > 0) {
            // Set the quantity first
            $qtyInput.val(quantity);

            // Trigger click on the real add to cart button
            $addToCartButton.first().trigger('click');
        }
        // Handle case when no add to cart button is found silently
    });
});
