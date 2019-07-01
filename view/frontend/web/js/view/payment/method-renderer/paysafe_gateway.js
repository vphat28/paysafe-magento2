/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'ko',
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Payment/js/model/credit-card-validation/validator'
    ],
    function (ko, $, Component, validator) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Paysafe_Payment/payment/form',
                transactionResult: '',
                accordDChoice: '',
                accordDType: '',
                accordDGracePeriod: '',
                accordDPlanNumber: '',
            },

            initObservable: function () {
                this._super()
                    .observe([
                        'accordDChoice',
                        'accordDType',
                        'accordDPlanNumber',
                        'accordDGracePeriod',
                    ]);

                return this;
            },

            /**
             * @returns {Boolean}
             */
            isShowLegend: function () {
                return true;
            },

            getThisObject: function () {
                return this;
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

            getAccordDTypes: function () {
                return [
                    {
                        'value': '1',
                        'text': 'Deferred'
                    },
                    {
                        'value': '2',
                        'text': 'Equal'
                    }
                ]
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
                        'accordDChoice': this.accordDChoice(),
                        'accordDType': this.accordDType(),
                        'accordDGracePeriod': this.accordDGracePeriod(),
                        'accordDPlanNumber': this.accordDPlanNumber(),
                    }
                };
            },
        });
    }
);