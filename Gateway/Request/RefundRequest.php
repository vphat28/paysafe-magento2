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

    /** @var \Paysafe\Payment\Model\Logger  */
    private $logger;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        ConfigInterface $config,
        \Paysafe\Payment\Model\Logger $logger
    )
    {
        $this->config = $config;
        $this->logger = $logger;
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

        $this->logger->debug('Refunding request ' . json_encode($buildSubject));

        return [
            'TXN_TYPE' => 'REFUND',
            'ORDER' => $orderDO,
            'AMOUNT' => isset($buildSubject['amount']) ? $buildSubject['amount'] : 0,
            'CURRENCY' => $order->getCurrencyCode(),
            'TXN_ID' => $payment->getLastTransId()
        ];
    }
}
