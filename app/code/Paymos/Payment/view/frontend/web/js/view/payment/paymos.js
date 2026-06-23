/**
 * Registers the Paymos payment method renderer with the checkout renderer list.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'paymos',
        component: 'Paymos_Payment/js/view/payment/method-renderer/paymos'
    });

    return Component.extend({});
});
