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
        if(parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 2) {
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

      placeOrderWrap: function () {
        var self = this;
        var paysafe3ds = window.paysafe;
        if($('#co-transparent-form-paysafe').validation('isValid')) {
          if(parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 1) {
            self.redirectAfterPlaceOrder = false;

            return self.placeOrder();
          }

          // If 3ds version 2
          if(parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 2) {
            fullScreenLoader.startLoader();
            if(self.getTestMode()) {
              var environment = 'TEST';
            }else {
              var environment = 'LIVE';
            }

            paysafe3ds.threedsecure.start(window.checkoutConfig.payment.paysafe_gateway.base64apikey, {
              environment: environment,
              accountId: window.checkoutConfig.payment.paysafe_gateway.accountid,
              card: {
                cardBin: self.creditCardNumber().substring(0, 8)
              }
            }, function (deviceFingerprintingId, error) {
              if(typeof deviceFingerprintingId === 'undefined') {
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
                  if(data.status === 'threed2completed') {
                    self.completedTxnId = data.dataLoad.id;
                    return self.placeOrder();
                  }
                });
            });
          }

          if(parseInt(window.checkoutConfig.payment.paysafe_gateway.threedsecuremode) === 0) {
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
