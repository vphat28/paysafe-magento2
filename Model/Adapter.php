<?php

namespace Paysafe\Payment\Model;

class Adapter extends \Magento\Payment\Model\Method\Adapter
{
    public function getConfigPaymentAction()
    {
        $threedsecure = \intval($this->getConfigData('threedsecure'));

        if ($threedsecure === 1) {
            return 'authorize';
        }

        if ($threedsecure == 2) {
            return 'authorize_capture';
        }

        return parent::getConfigPaymentAction();
    }
}