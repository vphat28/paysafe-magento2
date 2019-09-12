<?php

namespace Paysafe\Payment\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;

class TxnIdHandler implements HandlerInterface
{
    const TXN_ID = 'id';

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];

        $payment = $paymentDO->getPayment();

        /** @var Order $order */
        $order = $payment->getOrder();

        /** @var $payment \Magento\Sales\Model\Order\Payment */
        $payment->setTransactionId($response[self::TXN_ID]);
        $payment->setIsTransactionClosed(false);

        if (isset($response['TXN_TYPE']) && $response['TXN_TYPE'] === 'A') {
            $order->setStatus('pending');
            $order->setState('new');
            $payment->setAdditionalInformation('paysafe_txn_id', $response[self::TXN_ID]);
        }

        if (isset($response['TXN_TYPE']) && $response['TXN_TYPE'] === 'S') {
            $payment->setAdditionalInformation('paysafe_txn_id', $response[self::TXN_ID]);
            $payment->setAdditionalInformation('paysafe_settlement_txn_id', $response[self::TXN_ID]);
        }

        if (isset($response['TXN_TYPE']) && $response['TXN_TYPE'] === 'A_3DS') {
            if (isset($response['acsURL'])) {
                $payment->setAdditionalInformation('3ds_redirect_url', $response['acsURL']);
                $payment->setAdditionalInformation('paysafe_pareq', $response['paReq']);
                $payment->setAdditionalInformation('enrollcheck_id', $response['id']);
                $payment->setAdditionalInformation('auth_params', $response['auth_params']);
            }

            $order->setStatus('pending');
            $order->setState('new');
        }
    }
}
