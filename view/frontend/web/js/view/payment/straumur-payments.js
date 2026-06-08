define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';

        rendererList.push({
            type: 'straumur_payment',
            component: 'Straumur_Payment/js/view/payment/method-renderer/straumur-payment'
        });

        /** Add view logic here if needed */
        return Component.extend({});
    }
);