<?php

namespace Paysafe\Payment\Model;

use Magento\Payment\Model\InfoInterface;

class Adapter extends \Magento\Payment\Model\Method\Adapter
{
    public function authorize(InfoInterface $payment, $amount)
    {
        return parent::authorize($payment, $amount);
    }
}
