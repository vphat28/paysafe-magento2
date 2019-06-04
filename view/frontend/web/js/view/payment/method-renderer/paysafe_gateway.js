/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Payment/js/view/payment/cc-form'
    ],
    function ($, Component) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Paysafe_Payment/payment/form',
                transactionResult: ''
            },

            /**
             * @returns {Boolean}
             */
            isShowLegend: function () {
                return true;
            },

            getCode: function() {
                return 'paysafe_gateway';
            },

            isActive: function () {
                return window.checkoutConfig.payment.paysafe_gateway.active;
            },

            initialize: function () {
                this._super();
            },

            initElement: function () {
                this._super();
                this.initValidation();
            },

            placeOrder: function () {
                if ($('#co-transparent-form-paysafe').validation('isValid')) {
                    return this._super();
                }

                return false;
            },

            initValidation: function () {
                $('#co-transparent-form-paysafe').on('keyup  paste', 'input, select, textarea', function() {
                    $('#co-transparent-form-paysafe').validation('isValid');
                });
            },

            /**
             * Get payment method data
             */
            getData: function () {
                return {
                    'method': this.getCode(),
                    'additional_data': {
                        'ccNumber': this.creditCardNumber(),
                        'ccMonth': this.creditCardExpMonth(),
                        'ccYear': this.creditCardExpYear(),
                        'ccCVN': this.creditCardVerificationNumber(),
                    }
                };
            },
        });
    }
);