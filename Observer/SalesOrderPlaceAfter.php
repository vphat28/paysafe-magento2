<?php

namespace Paysafe\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Sales\Model\Order;
use Paysafe\Payment\Helper\Data;
use Paysafe\Payment\Model\Ui\ConfigProvider;

class SalesOrderPlaceAfter extends AbstractDataAssignObserver
{
    /** @var Data */
    private $helper;

    public function __construct(Data $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getData('order');
        $payment = $order->getPayment();

        if ($payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        if ($this->helper->getPaymentAction($order->getStore()) === 'authorize') {
            $order->setState('new');
            $order->setStatus('pending');
        }
    }
}
