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
        'Magento_Payment/js/model/credit-card-validation/validator',
        'mage/url',
        'paysafe3ds2sdk',
        'Magento_Checkout/js/model/full-screen-loader'
    ],
    function (ko, $, Component, validator, urlBuilder, paysafe, fullScreenLoader) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Paysafe_Payment/payment/form',
                transactionResult: '',
                completedTxnId: '',
                eci: '',
                cavv: '',
                threed_id: '',
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

            afterPlaceOrder: function () {
                if (parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 2) {
                    return;
                }

                window.location.href = urlBuilder.build('paysafe/cc/redirect');
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

            getCode: function () {
                return 'paysafe_gateway';
            },

            isActive: function () {
                return window.checkoutConfig.payment.paysafe_gateway.active;
            },

            isEnableAccordD: function () {
                return window.checkoutConfig.payment.paysafe_gateway.enable_accordD;
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

            getTestMode: function () {
                return window.checkoutConfig.payment.paysafe_gateway.testmode;
            },

            doChallenge: function (id) {
                var self = this;
                var url = '/paysafe/cc/threedauthenticationchallenge';
                var request = $.ajax({
                    url: url,
                    method: "POST",
                    dataType: "json",
                    data: {
                        "id": id,
                    }
                })
                    .done(function (data) {
                        data = JSON.parse(data);
                        if (data.status === 'threed2completed') {
                            fullScreenLoader.stopLoader();
                            self.eci = data.dataLoad.eci;
                            self.cavv = data.dataLoad.cavv;
                            self.threed_id = data.dataLoad.id;
                            return self.placeOrder();
                        } else {
                            alert('Error in 3DS version 2');
                            fullScreenLoader.stopLoader();
                        }
                    });
            },

            placeOrderWrap: function () {
                var self = this;
                var paysafe3ds = window.paysafe;
                if ($('#co-transparent-form-paysafe').validation('isValid')) {
                    if (parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 1) {
                        self.redirectAfterPlaceOrder = false;

                        return self.placeOrder();
                    }

                    // If 3ds version 2
                    if (parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 2) {
                        fullScreenLoader.startLoader();
                        if (self.getTestMode()) {
                            var environment = 'TEST';
                        } else {
                            var environment = 'LIVE';
                        }

                        paysafe3ds.threedsecure.start(window.checkoutConfig.payment.paysafe_gateway.base64apikey, {
                            environment: environment,
                            accountId: window.checkoutConfig.payment.paysafe_gateway.accountid,
                            card: {
                                cardBin: self.creditCardNumber().substring(0, 8)
                            }
                        }, function (deviceFingerprintingId, error) {
                            if (typeof deviceFingerprintingId === 'undefined') {
                                fullScreenLoader.stopLoader();
                                alert(error.detailedMessage);
                                return;
                            }
                            var url = '/paysafe/cc/threedauthentication';
                            var request = $.ajax({
                                url: url,
                                method: "POST",
                                dataType: "json",
                                beforeSend: function (xhr) {
                                    /* Authorization header */
                                    xhr.setRequestHeader("Authorization", "Basic " + window.checkoutConfig.payment.paysafe_gateway.base64apikey);
                                },
                                data: {
                                    "deviceFingerprintingId": deviceFingerprintingId,
                                    "card": {
                                        "cardExpiry": {
                                            "month": self.creditCardExpMonth(),
                                            "year": self.creditCardExpYear()
                                        },
                                        "cardNum": self.creditCardNumber()
                                    },
                                }
                            })
                                .done(function (data) {
                                    data = JSON.parse(data);
                                    console.log(data);
                                    if (data.status === 'threed2completed') {
                                        self.cavv = data.dataLoad.cavv;
                                        self.threed_id = data.dataLoad.id;
                                        self.eci = data.dataLoad.eci;

                                        return self.placeOrder();
                                    } else if (data.status === 'threed2pending') {
                                        paysafe3ds.threedsecure.challenge(window.checkoutConfig.payment.paysafe_gateway.base64apikey, {
                                            environment: environment,
                                            sdkChallengePayload: data.three_d_auth.sdkChallengePayload
                                        }, function (id, error) {
                                            if (id) {
                                                self.doChallenge(id);
                                            }
                                        });
                                    } else {
                                        alert('Error in 3DS version 2');
                                        fullScreenLoader.stopLoader();
                                    }
                                })
                                .error(function (data) {
                                    alert('Gateway error: ' + data.statusText);
                                    console.log(data);
                                    fullScreenLoader.stopLoader();
                                })
                            ;
                        });
                    }

                    if (parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 0) {
                        return self.placeOrder();
                    }
                }
            },

            initValidation: function () {
                $('#co-transparent-form-paysafe').on('keyup  paste', 'input, select, textarea', function () {
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
                        'completedTxnId': this.completedTxnId,
                        'eci': this.eci,
                        'cavv': this.cavv,
                        'threed_id': this.threed_id,
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
