<?php

namespace Paysafe\Payment\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

class RefundRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        ConfigInterface $config
    )
    {
        $this->config = $config;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];

        $payment = $paymentDO->getPayment();
        $orderDO = $payment->getOrder();
        $order = $paymentDO->getOrder();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException('Order payment should be provided.');
        }

        if (isset($body['AMOUNT']) && !empty($body['AMOUNT'])) {
            $refundParams['amount'] = (int)$body['AMOUNT'] * $this->helper->getCurrencyMultiplier($body['CURRENCY']);
        }

        file_put_contents(BP . '/var/log/paysafe.log', 'refunding object ' . json_encode($buildSubject), FILE_APPEND);

        return [
            'TXN_TYPE' => 'REFUND',
            'ORDER' => $orderDO,
            'AMOUNT' => isset($buildSubject['amount']) ? $buildSubject['amount'] : 0,
            'CURRENCY' => $order->getCurrencyCode(),
            'TXN_ID' => $payment->getLastTransId()
        ];
    }
}
