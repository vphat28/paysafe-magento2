<?php

namespace Paysafe\Payment\Gateway\Http\Client;

use Magento\Framework\Exception\LocalizedException;
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

class ClientMock implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    /**
     * @var array
     */
    private $results = [
        self::SUCCESS,
        self::FAILURE
    ];

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

    public function __construct(
        Logger $logger,
        Data $helper,
        PaysafeClient $paysafeClient,
        DataProvider $dataProvider
    ) {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->paysafeClient = $paysafeClient;
        $this->dataProvider = $dataProvider;
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

        $auth = $client->cardPaymentService()->authorize(new Authorization(array(
            'merchantRefNum' => $body['INVOICE'],
            'amount' => $body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']),
            'settleWithAuth' => $capture,
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
            )
        ));

        $response = $auth->jsonSerialize();

        $this->logger->debug(
            [
                'request' => $transferObject->getBody()
            ]
        );

        $response['TXN_TYPE'] = $body['TXN_TYPE'];

        return $response;
    }

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
