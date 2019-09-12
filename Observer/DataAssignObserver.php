<?php

namespace Paysafe\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Paysafe\Payment\Model\DataProvider;

class DataAssignObserver extends AbstractDataAssignObserver
{
    /** @var DataProvider */
    private $dataProvider;

    public function __construct(DataProvider $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $method = $this->readMethodArgument($observer);
        $data = $this->readDataArgument($observer);

        try {
            $paymentInfo = $method->getInfoInstance();
        } catch (\Exception $e) {
        }

        $additionalData = $data->getData('additional_data');

        if (!is_array($additionalData)) {
            return;
        }

        foreach (['ccNumber', 'ccMonth', 'ccYear', 'ccCVN', 'accordDChoice', 'accordDType', 'accordDGracePeriod', 'accordDPlanNumber', 'completedTxnId'] as $key) {
            if (isset($additionalData[$key])) {
                $this->dataProvider->setAdditionalData($key, $additionalData[$key]);
            }
        }
    }
}
