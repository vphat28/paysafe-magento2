<?php

namespace Paysafe\Payment\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

class CaptureRequest implements BuilderInterface
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
    ) {
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

        $order = $paymentDO->getOrder();
        $orderId = $order->getId();

        $payment = $paymentDO->getPayment();
        $billingAddress = $order->getBillingAddress();
        $orderDO = $payment->getOrder();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException('Order payment should be provided.');
        }

        if ($orderId) {
            $type = 'S_only';
        } else {
            $type = 'S';
        }

        return [
            'TXN_TYPE' => $type,
            'ORDER' => $orderDO,
            'INVOICE' => $order->getOrderIncrementId(),
            'POSTCODE' => $billingAddress->getPostcode(),
            'AMOUNT' => $order->getGrandTotalAmount(),
            'CURRENCY' => $order->getCurrencyCode(),
            'EMAIL' => $billingAddress->getEmail(),
            'TXN_ID' => $payment->getLastTransId(),
        ];
    }
}
