<?php

namespace Paysafe\Payment\Model\SourceModel;

class ThreeDSecureOptions  implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 2, 'label' => __('3DS Version 2')],
            ['value' => 1, 'label' => __('3DS Version 1')],
            ['value' => 0, 'label' => __('No')]
        ];
    }

}