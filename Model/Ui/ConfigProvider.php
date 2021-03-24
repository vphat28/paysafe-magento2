<?php

namespace Paysafe\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\Authorization\CustomerSessionUserContext;
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
        \Magento\Payment\Model\CcConfig $config,
        CustomerSessionUserContext $customer_session_user_context,
        \Magento\Customer\Model\CustomerFactory $customer_factory
    ) {
        $this->helper = $helper;
        $this->config = $config;
        $this->customer_factory              = $customer_factory;
        $this->customer_session_user_context = $customer_session_user_context;

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
        $cardTypes = [];
        $allCards = $this->config->getCcAvailableTypes();

        if (count($ccTypes) <= 0) {
            $cardTypes = $allCards;
        } else {
            foreach ($allCards as $key => $card) {
                if (in_array($key, $ccTypes)) {
                    $cardTypes[$key] = $card;
                }
            }
        }
        $userId = $this->customer_session_user_context->getUserId();
        $customer      = $this->customer_factory->create();
        $customer->load($userId);
        $cards = $customer->getData('paysafe_stored_cards');
        $cards = json_decode($cards, true);
        if (empty($cards)) {
            $cards = [];
        }
        $tokens = [];

        foreach ($cards as $card) {
            if (isset($card['tokenkey'])) {
                $tokens[] = $card;
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
                    'availableTypes' => [$methodCode => $cardTypes],
                ],
                self::CODE => [
                    'active' => $this->helper->isPaysafeActive(),
                    'active_saved_card' => $this->helper->isPaysafeSavedCardActive(),
                    'saved_cards' => $tokens,
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
