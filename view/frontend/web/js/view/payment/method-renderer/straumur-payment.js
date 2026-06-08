define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/url-builder',
        'mage/url',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/error-processor'
    ],
    function (
        $,
        Component,
        placeOrderAction,
        additionalValidators,
        quote,
        fullScreenLoader,
        urlBuilder,
        url,
        customer,
        errorProcessor
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Straumur_Payment/payment/straumur-form'
            },

            getCode: function() {
                return 'straumur_payment';
            },

            isActive: function() {
                return true;
            },

            /**
             * @returns {Boolean}
             */
            isAvailable: function () {
                return this.getCode() === this.isChecked();
            },

            /**
             * Get payment method description
             * @returns {String}
             */
            getDescription: function () {
                if (window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.straumur_payment) {
                    return window.checkoutConfig.payment.straumur_payment.description || '';
                }
                return '';
            },

            /**
             * Place order and redirect to Straumur hosted checkout
             */
            placeOrder: function () {
                var self = this;

                if (additionalValidators.validate()) {
                    fullScreenLoader.startLoader();
                    this.isPlaceOrderActionAllowed(false);

                    // First, place the order
                    $.when(
                        placeOrderAction(this.getData(), this.messageContainer)
                    ).done(function () {
                        // After order is placed, get the redirect URL
                        $.ajax({
                            url: url.build('straumur/checkout/getRedirectUrl'),
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                form_key: $.mage.cookies.get('form_key')
                            }
                        }).done(function (response) {
                            if (response.success && response.redirect_url) {
                                // Redirect to Straumur hosted checkout
                                window.location.href = response.redirect_url;
                            } else {
                                self.isPlaceOrderActionAllowed(true);
                                fullScreenLoader.stopLoader();
                                if (response.message) {
                                    self.messageContainer.addErrorMessage({
                                        message: response.message
                                    });
                                }
                            }
                        }).fail(function (response) {
                            self.isPlaceOrderActionAllowed(true);
                            fullScreenLoader.stopLoader();
                            self.messageContainer.addErrorMessage({
                                message: 'Unable to retrieve payment URL. Please try again.'
                            });
                        });
                    }).fail(function (response) {
                        self.isPlaceOrderActionAllowed(true);
                        fullScreenLoader.stopLoader();
                        errorProcessor.process(response, self.messageContainer);
                    });
                }
            }
        });
    }
);