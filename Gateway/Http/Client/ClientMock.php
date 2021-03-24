<?php

namespace Paysafe\Payment\Gateway\Http\Client;

use Magento\Customer\Model\Authorization\CustomerSessionUserContext;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Store\Model\StoreManagerInterface;
use Paysafe\Payment\Model\Logger;
use Magento\Sales\Model\Order;
use Paysafe\CardPayments\AuthorizationReversal;
use Paysafe\CardPayments\Refund;
use Paysafe\CardPayments\Settlement;
use Paysafe\CardPayments\Authorization;
use Paysafe\CustomerVault\Profile;
use Paysafe\CustomerVault\Address;
use Paysafe\Payment\Helper\Data;
use Paysafe\Payment\Model\DataProvider;
use Paysafe\Payment\Model\PaysafeClient;
use Paysafe\PaysafeException;
use Paysafe\RequestConflictException;
use Paysafe\ThreeDSecure\ThreeDEnrollment;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Client;

class ClientMock implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    /**
     * @var Logger
     */
    private $logger;

    /** @var Data */
    private $helper;

    /** @var PaysafeClient */
    private $paysafeClient;

    /** @var DataProvider */
    private $dataProvider;

    /** @var UrlInterface */
    private $url;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        Logger $logger,
        Data $helper,
        PaysafeClient $paysafeClient,
        DataProvider $dataProvider,
        ClientFactory $clientFactory,
        UrlInterface $url,
        CustomerSessionUserContext $customer_session_user_context,
        \Magento\Customer\Model\CustomerFactory $customer_factory,
        StoreManagerInterface $storeManager
    ) {
        $this->clientFactory                 = $clientFactory;
        $this->logger                        = $logger;
        $this->helper                        = $helper;
        $this->paysafeClient                 = $paysafeClient;
        $this->dataProvider                  = $dataProvider;
        $this->url                           = $url;
        $this->storeManager                  = $storeManager;
        $this->customer_factory              = $customer_factory;
        $this->customer_session_user_context = $customer_session_user_context;
    }

    /**
     * @param TransferInterface $transferObject
     *
     * @return array|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Paysafe\PaysafeException
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $body   = $transferObject->getBody();
        $userId = $this->customer_session_user_context->getUserId();

        if ( ! empty($this->dataProvider->getAdditionalData('completedTxnId'))) {
            return $this->proceedCompletedPayment($body);
        }

        if ($body['TXN_TYPE'] === 'S_only') {
            return $this->placeCaptureOnly($body);
        }

        if ($body['TXN_TYPE'] === 'REFUND') {
            return $this->placeRefundOnly($body);
        }

        if ($body['TXN_TYPE'] === 'CANCEL') {
            return $this->placeCancelOnly($body);
        }

        $this->helper->initPaysafeSDK();
        $client = $this->paysafeClient->getClient($this->storeManager->getStore());

        if ($body['TXN_TYPE'] === 'S') {
            $capture = true;
        } else {
            $capture = false;
        }

        $threeDSecureMode = $this->helper->threedsecureMode();

        /** @var Order $order */
        $order             = $body['ORDER'];
        $billingAddress    = $order->getBillingAddress();
        $fname             = $billingAddress->getFirstname();
        $lname             = $billingAddress->getLastname();
        $billing_address_1 = implode(' ', $billingAddress->getStreet());
        $cardNumber        = $this->dataProvider->getAdditionalData('ccNumber');
        $cardMonth         = $this->dataProvider->getAdditionalData('ccMonth');
        $cardYear          = $this->dataProvider->getAdditionalData('ccYear');
        $saveTheCard       = $this->dataProvider->getAdditionalData('saveTheCard');

        $authParams = array(
            'merchantRefNum' => $body['INVOICE'],
            'amount'         => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
            'settleWithAuth' => $capture,
            'profile'        => [
                'firstName' => $billingAddress->getFirstname(),
                'lastName'  => $billingAddress->getLastname(),
                'email'     => $billingAddress->getEmail(),
            ],
            'customerIp'     => $_SERVER['REMOTE_ADDR'],
            'card'           => array(
                'cardNum'    => $this->dataProvider->getAdditionalData('ccNumber'),
                'cvv'        => $this->dataProvider->getAdditionalData('ccCVN'),
                'cardExpiry' => array(
                    'month' => $this->dataProvider->getAdditionalData('ccMonth'),
                    'year'  => $this->dataProvider->getAdditionalData('ccYear'),
                ),
            ),
            'billingDetails' => [
                "zip"     => $body['POSTCODE'],
                "street"  => implode('', $billingAddress->getStreet()),
                "city"    => $billingAddress->getCity(),
                "state"   => $billingAddress->getRegionCode(),
                "country" => $billingAddress->getCountryId(),
                "phone"   => $billingAddress->getTelephone(),
            ],
        );

        if ($this->dataProvider->getAdditionalData('selectedMethod') == 'mer_paysafe_credit_card_token') {
            $authParams['card'] = [
                'paymentToken' => $this->dataProvider->getAdditionalData('cardPaymentToken'),
            ];
        }

        $this->logger->debug('Authorization request', $authParams);

        if ( ! empty($this->dataProvider->getAdditionalData('accordDChoice'))) {
            $authParams['accordD'] = [
                'financingType'                                                                         => $this->dataProvider->getAdditionalData('accordDType') === '1' ? 'DEFERRED_PAYMENT' : 'EQUAL_PAYMENT',
                'plan'                                                                                  => $this->dataProvider->getAdditionalData('accordDPlanNumber'),
                $this->dataProvider->getAdditionalData('accordDType') === '1' ? 'gracePeriod' : 'terms' => $this->dataProvider->getAdditionalData('accordDGracePeriod'),
            ];
        }

        if ( ! empty($this->dataProvider->getAdditionalData('threed_id'))) {
            /** @var Order $order */
            $order    = $body['ORDER'];
            $quoteId  = $order->getQuoteId();
            $auth3dID = $this->dataProvider->getAdditionalData('threed_id');

            $authResponse = $this->helper->getThreeDResult($auth3dID);

            $merchantRefNum = $authResponse['merchantRefNum'];
            $merchantRefNum = explode('-', $merchantRefNum);

            if ( ! isset($merchantRefNum[1]) || $merchantRefNum[1] != $quoteId) {
                throw new LocalizedException(__('Cheat!'));
            }

            if (
                $authResponse["status"] === 'PENDING' &&
                version_compare($authResponse['threeDSecureVersion'], '2.0') < 0 &&
                $authResponse["threeDEnrollment"] == 'Y'
            ) {
                $response                = $authResponse;
                $response['TXN_TYPE']    = 'A_3DS';
                $response['auth_params'] = json_encode($authParams);

                return $response;
            }

            $authParams['authentication']['xid'] = $auth3dID;
            $authParams['authentication']['eci'] = $authResponse['eci'];

            if ( ! empty($this->dataProvider->getAdditionalData('cavv'))) {
                $authParams['authentication']['cavv'] = $this->dataProvider->getAdditionalData('cavv');
            }
        }

        if ($threeDSecureMode === 2) {
            if (empty($this->dataProvider->getAdditionalData('threed_id'))) {
                throw new LocalizedException(__('Not 3DS transaction'));
            }
        }

        if ($threeDSecureMode === 1) {
            $hash             = hash('crc32', $this->url->getBaseUrl());
            $enrollmentChecks = $client->threeDSecureService()->enrollmentChecks(new ThreeDEnrollment(array(
                'merchantRefNum' => $hash . time() . "-enrollmentchecks",
                'amount'         => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
                'currency'       => strtoupper($body['CURRENCY']),
                'card'           => array(
                    'cardNum'    => $this->dataProvider->getAdditionalData('ccNumber'),
                    'cvv'        => $this->dataProvider->getAdditionalData('ccCVN'),
                    'cardExpiry' => array(
                        'month' => $this->dataProvider->getAdditionalData('ccMonth'),
                        'year'  => $this->dataProvider->getAdditionalData('ccYear'),
                    ),
                ),
                'customerIp'     => $_SERVER['REMOTE_ADDR'],
                'userAgent'      => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : $_SERVER['REMOTE_ADDR'],
                'acceptHeader'   => "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
                'merchantUrl'    => $this->url->getBaseUrl(),
            )))->jsonSerialize();

            if (isset($enrollmentChecks['threeDEnrollment']) && $enrollmentChecks['threeDEnrollment'] === 'Y') {
                $response                = $enrollmentChecks;
                $response['TXN_TYPE']    = 'A_3DS';
                $response['auth_params'] = json_encode($authParams);

                return $response;
            }
        }
        try {
            if ($saveTheCard && ! empty($userId) && $this->dataProvider->getAdditionalData('selectedMethod') == 'mer_paysafe_credit_card') {
                $profile = $client->customerVaultService()->createProfile(new Profile(array(
                    "merchantCustomerId" => uniqid('cust-' . date('m/d/Y h:i:s a', time())),
                    "locale"             => "en_US",
                    "firstName"          => $fname,
                    "lastName"           => $lname,
                    "email"              => $billingAddress->getEmail(),
                )));


                $address      = $client->customerVaultService()->createAddress(new Address(array(
                    "nickName"  => "home",
                    'street'    => $billing_address_1,
                    'city'      => $billingAddress->getCity(),
                    'country'   => $billingAddress->getCountryId(),
                    'zip'       => $billingAddress->getPostcode(),
                    "profileID" => $profile->id,
                )));
                $card_request = array(
                    "nickName"         => "Default Card",
                    "holderName"       => $fname . ' ' . $lname,
                    'cardNum'          => $cardNumber,
                    'cardExpiry'       => array(
                        'month' => $cardMonth,
                        'year'  => $cardYear,
                    ),
                    'billingAddressId' => $address->id,
                    "profileID"        => $profile->id,
                );

                $card = $client->customerVaultService()->createCard(new \Paysafe\CustomerVault\Card(
                        $card_request
                    )
                );
                $storeCc             = substr( $cardNumber, 0, 4 ) . str_repeat( "*", strlen( $cardNumber ) - 8 ) . substr( $cardNumber, - 4 );
                $responsearray = array(
                    'tokenkey'         => $profile->paymentToken,
                    'cardbin'          => $card->cardBin,
                    'cardpaymenttoken' => $card->paymentToken,
                    'cardid'           => $card->id,
                    'profileid'        => $profile->id,
                    'storecc'        => $storeCc,
                    'paysafe_date_of_card_used'        => date( "jS F Y" ),
                );
                $customer      = $this->customer_factory->create();
                $customer->load($userId);
                $cards = $customer->getData('paysafe_stored_cards');

                if (empty($cards)) {
                    $cards = [];
                } else {
                    $cards = json_decode($cards, true);
                }

                $cards[] = $responsearray;
                $customer->setData('paysafe_stored_cards', json_encode($cards));
                $customer->save();
            }
            $auth = $client->cardPaymentService()->authorize(new Authorization($authParams));
        } catch (\Exception $exception) {
            throw new LocalizedException(__($exception->getMessage()));
        }

        $response = $auth->jsonSerialize();

        if (isset($response['status']) && $response['status'] === 'COMPLETED') {
            $response['TXN_TYPE'] = $body['TXN_TYPE'];

            return $response;
        } else {
            throw new LocalizedException(__('Payment has been declined with code' . $response['authCode']));
        }
    }

    private function proceedCompletedPayment($body)
    {
        /** @var Client $client */
        $client = $this->clientFactory->create();

        if ($this->helper->isTestMode()) {
            $url = 'https://api.test.paysafe.com/cardpayments/v1/accounts/' . $this->helper->getAccountNumber() . '/settlements/' . $this->dataProvider->getAdditionalData('completedTxnId');
        } else {
            $url = 'https://api.paysafe.com/cardpayments/v1/accounts/' . $this->helper->getAccountNumber() . '/settlements/' . $this->dataProvider->getAdditionalData('completedTxnId');
        }

        $response = $client->get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->helper->getAPIUsername() . ':' . $this->helper->getAPIPassword()),
                'Content-type'  => 'application/json',
            ],
        ]);

        $response             = json_decode($response->getBody()->getContents(), true);
        $response['TXN_TYPE'] = 'S';

        return $response;
    }

    /**
     * @param $body
     *
     * @return array|Settlement
     * @throws LocalizedException
     * @throws PaysafeException
     */
    private function placeCaptureOnly($body)
    {
        /** @var Order $order */
        $order = $body['ORDER'];
        $this->helper->initPaysafeSDK();
        $client = $this->paysafeClient->getClient($order->getStore());

        try {
            $response = $client->cardPaymentService()->settlement(new Settlement(array(
                'merchantRefNum'    => $order->getIncrementId(),
                'authorizationID'   => $order->getPayment()->getAdditionalInformation('paysafe_txn_id'),
                'status'            => 'COMPLETED',
                'availableToRefund' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
                'amount'            => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
            )));
        } catch (RequestConflictException $exception) {
            if ($exception->getCode() === 5031) {
                return ['id' => $order->getPayment()->getAdditionalInformation('paysafe_txn_id')];
            }
        }

        $response = $response->jsonSerialize();
        $order->getPayment()->setAdditionalInformation('paysafe_settlement_txn_id', $response['id']);

        return $response;
    }

    /**
     * @param $body
     *
     * @return array
     * @throws LocalizedException
     * @throws PaysafeException
     */
    private function placeRefundOnly($body)
    {
        /** @var Order $order */
        $order = $body['ORDER'];
        $this->helper->initPaysafeSDK();
        $payment = $order->getPayment();
        $store   = $order->getStore();
        $txnId   = $payment->getAdditionalInformation('paysafe_settlement_txn_id');
        $client  = $this->paysafeClient->getClient($store);


        $refundParams = array(
            'merchantRefNum' => $order->getIncrementId(),
            'dupCheck'       => false,
            'settlementID'   => $txnId,
        );

        if ( ! empty($body['AMOUNT'])) {
            $refundParams['amount'] = $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']);
        }

        $this->logger->debug('refunding ' . json_encode($refundParams));

        $response = $client->cardPaymentService()->refund(new Refund($refundParams));

        return $response->jsonSerialize();
    }

    /**
     * @param $body
     *
     * @return array
     * @throws LocalizedException
     * @throws PaysafeException
     */
    private function placeCancelOnly($body)
    {
        /** @var Order $order */
        $order = $body['ORDER'];
        $this->helper->initPaysafeSDK();
        $payment = $order->getPayment();
        $txnId   = $payment->getAdditionalInformation('paysafe_txn_id');
        $client  = $this->paysafeClient->getClient($order->getStore());

        try {
            $authReversal = new AuthorizationReversal(array(
                'merchantRefNum'  => $order->getIncrementId(),
                'authorizationID' => $txnId,
            ));

            $response = $client->cardPaymentService()->reverseAuth($authReversal);
        } catch (PaysafeException $exception) {
            if ($exception->getCode() === 5031) {
                return ['id' => $order->getPayment()->getAdditionalInformation('paysafe_txn_id')];
            }

            throw new LocalizedException(__($exception->getMessage()));
        }

        return $response->jsonSerialize();
    }
}
