<?php

namespace Paysafe\Payment\Gateway\Http\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Paysafe\CardPayments\AuthorizationReversal;
use Paysafe\CardPayments\Refund;
use Paysafe\CardPayments\Settlement;
use Paysafe\Payment\Helper\Data;
use Paysafe\Payment\Model\DataProvider;
use Paysafe\Payment\Model\PaysafeClient;
use Paysafe\CardPayments\Authorization;
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

    public function __construct(
        Logger $logger,
        Data $helper,
        PaysafeClient $paysafeClient,
        DataProvider $dataProvider,
        ClientFactory $clientFactory,
        UrlInterface $url
    ) {
        $this->clientFactory = $clientFactory;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->paysafeClient = $paysafeClient;
        $this->dataProvider = $dataProvider;
        $this->url = $url;
    }

    /**
     * @param TransferInterface $transferObject
     * @return array|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Paysafe\PaysafeException
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $body = $transferObject->getBody();

        if (!empty($this->dataProvider->getAdditionalData('completedTxnId'))) {
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
        $client = $this->paysafeClient->getClient();

        if ($body['TXN_TYPE'] === 'S') {
            $capture = true;
        } else {
            $capture = false;
        }

        $threeDSecureMode = $this->helper->threedsecureMode();

        /** @var Order $order */
        $order = $body['ORDER'];
        $billingAddress = $order->getBillingAddress();

        $authParams = array(
            'merchantRefNum' => $body['INVOICE'],
            'amount' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
            'settleWithAuth' => $capture,
            'profile' => [
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'email' => $billingAddress->getEmail(),
            ],
            'customerIp' => $_SERVER['REMOTE_ADDR'],
            'card' => array(
                'cardNum' => $this->dataProvider->getAdditionalData('ccNumber'),
                'cvv' => $this->dataProvider->getAdditionalData('ccCVN'),
                'cardExpiry' => array(
                    'month' => $this->dataProvider->getAdditionalData('ccMonth'),
                    'year' => $this->dataProvider->getAdditionalData('ccYear')
                )
            ),
            'billingDetails' => [
                "zip" => $body['POSTCODE'],
            ],
        );

        if (!empty($this->dataProvider->getAdditionalData('accordDChoice'))) {
            $authParams['accordD'] = [
                'financingType' => $this->dataProvider->getAdditionalData('accordDType') === '1' ? 'DEFERRED_PAYMENT' : 'EQUAL_PAYMENT',
                'plan' => $this->dataProvider->getAdditionalData('accordDPlanNumber'),
                $this->dataProvider->getAdditionalData('accordDType') === '1' ? 'gracePeriod' : 'terms' => $this->dataProvider->getAdditionalData('accordDGracePeriod'),
            ];
        }

        if ($threeDSecureMode > 0) {
            $hash = hash('crc32', $this->url->getBaseUrl());
            $enrollmentChecks = $client->threeDSecureService()->enrollmentChecks(new ThreeDEnrollment(array(
                'merchantRefNum' => $hash . time() . "-enrollmentchecks",
                'amount' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
                'currency' => strtoupper($body['CURRENCY']),
                'card' => array(
                    'cardNum' => $this->dataProvider->getAdditionalData('ccNumber'),
                    'cvv' => $this->dataProvider->getAdditionalData('ccCVN'),
                    'cardExpiry' => array(
                        'month' => $this->dataProvider->getAdditionalData('ccMonth'),
                        'year' => $this->dataProvider->getAdditionalData('ccYear')
                    )
                ),
                'customerIp' => $_SERVER['REMOTE_ADDR'],
                'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : $_SERVER['REMOTE_ADDR'],
                'acceptHeader' => "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
                'merchantUrl' => $this->url->getBaseUrl()
            )))->jsonSerialize();

            if (isset($enrollmentChecks['threeDEnrollment']) && $enrollmentChecks['threeDEnrollment'] === 'Y') {
                $response = $enrollmentChecks;
                $response['TXN_TYPE'] = 'A_3DS';
                $response['auth_params'] = json_encode($authParams);

                return $response;
            }
        }

        $auth = $client->cardPaymentService()->authorize(new Authorization($authParams));

        $response = $auth->jsonSerialize();

        $response['TXN_TYPE'] = $body['TXN_TYPE'];

        return $response;
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
                'Content-type' => 'application/json',
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);
        $response['TXN_TYPE'] = 'S';

        return $response;
    }

    /**
     * @param $body
     * @return array|Settlement
     * @throws LocalizedException
     * @throws PaysafeException
     */
    private function placeCaptureOnly($body)
    {
        $order = $body['ORDER'];
        $this->helper->initPaysafeSDK();
        $client = $this->paysafeClient->getClient();

        try {
        $response = $client->cardPaymentService()->settlement(new Settlement(array(
            'merchantRefNum' => $order->getIncrementId(),
            'authorizationID' => $order->getPayment()->getAdditionalInformation('paysafe_txn_id'),
            'status' => 'COMPLETED',
            'availableToRefund' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
            'amount' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
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
        $txnId = $payment->getAdditionalInformation('paysafe_settlement_txn_id');
        $client = $this->paysafeClient->getClient();

        try {
            $response = $client->cardPaymentService()->refund(new Refund(array(
                'merchantRefNum' => $order->getIncrementId(),
                'settlementID' => $txnId,
            )));
        } catch (PaysafeException $exception) {
            if ($exception->getCode() === 5031) {
                return ['id' => $order->getPayment()->getAdditionalInformation('paysafe_txn_id')];
            }

            throw new LocalizedException(__($exception->getMessage()));
        }

        return $response->jsonSerialize();
    }

    /**
     * @param $body
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
        $txnId = $payment->getAdditionalInformation('paysafe_txn_id');
        $client = $this->paysafeClient->getClient();

        try {
            $authReversal = new AuthorizationReversal(array(
                'merchantRefNum' => $order->getIncrementId(),
                'authorizationID' => $txnId
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
