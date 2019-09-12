<?php

namespace Paysafe\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Paysafe\Payment\Gateway\Http\Client\ClientMock;
use Paysafe\Payment\Helper\Data;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'paysafe_gateway';

    /** @var Data */
    private $helper;

    /** @var \Magento\Payment\Model\CcConfig */
    private $config;

    public function __construct(
        Data $helper,
        \Magento\Payment\Model\CcConfig $config
    ) {
        $this->helper = $helper;
        $this->config = $config;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $methodCode = self::CODE;
        $ccTypes = explode(',', $this->helper->getCCTypes());
        $cards = [];
        $allCards = $this->config->getCcAvailableTypes();

        if (count($ccTypes) <= 0) {
            $cards = $allCards;
        } else {
            foreach ($allCards as $key => $card) {
                if (in_array($key, $ccTypes)) {
                    $cards[$key] = $card;
                }
            }
        }

        $ccTypes = array_intersect($ccTypes, $this->config->getCcAvailableTypes());
        return [
            'payment' => [
                'ccform' => [
                    'months' => [
                        self::CODE => $this->config->getCcMonths(),
                    ],
                    'years' => [
                        self::CODE => $this->config->getCcYears(),
                    ],
                    'hasVerification' => [
                        self::CODE => true,
                    ],
                    'cvvImageUrl' => [$methodCode => $this->config->getCvvImageUrl()],
                    'availableTypes' => [$methodCode => $cards],
                ],
                self::CODE => [
                    'active' => $this->helper->isPaysafeActive(),
                    'testmode' => $this->helper->isTestMode(),
                    'base64apikey' => base64_encode($this->helper->getSingleUseToken()),
                    'accountid' => $this->helper->getAccountNumber(),
                    'threedsecuremode' => $this->helper->threedsecureMode(),
                    'enable_accordD' => $this->helper->isEnableAccordD(),
                    'transactionResults' => [
                        ClientMock::SUCCESS => __('Success'),
                        ClientMock::FAILURE => __('Fraud')
                    ]
                ]
            ]
        ];
    }
}
