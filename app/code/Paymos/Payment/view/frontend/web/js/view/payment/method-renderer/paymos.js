/**
 * Paymos checkout method renderer.
 *
 * Extends the default payment component but suppresses Magento's own success
 * redirect (redirectAfterPlaceOrder = false) and, after the order is placed,
 * sends the customer to the Paymos redirect controller — which creates the
 * invoice and forwards to the hosted checkout. Without this the order would be
 * placed and the buyer would land on the default success page instead of paying.
 */
define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Paymos_Payment/payment/paymos',
            redirectAfterPlaceOrder: false
        },

        /**
         * @returns {String}
         */
        getTitle: function () {
            return window.checkoutConfig.payment.paymos.title;
        },

        /**
         * Redirect to the Paymos hosted checkout after the order is placed.
         */
        afterPlaceOrder: function () {
            window.location.replace(window.checkoutConfig.payment.paymos.redirectUrl);
        }
    });
});
