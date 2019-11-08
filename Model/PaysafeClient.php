<?php

namespace Paysafe\Payment\Model;

use Magento\Framework\Exception\LocalizedException;
use Paysafe\Payment\Helper\Data;
use Paysafe\PaysafeApiClient;
use Paysafe\Environment;
use Paysafe\CardPayments\Authorization;


class PaysafeClient
{
    /** @var Data */
    private $helper;

    private $client = null;

    public function __construct(Data $helper)
    {
        $this->helper = $helper;
    }

    public function getClient($store = null)
    {
        if ($this->client === null) {
            $paysafeApiKeyId = $this->helper->getAPIUsername($store);
            $paysafeApiKeySecret = $this->helper->getAPIPassword($store);
            $paysafeAccountNumber = $this->helper->getAccountNumber($store);
            $mode = $this->helper->isTestMode() ? Environment::TEST : Environment::LIVE;
            try {
                $client = new PaysafeApiClient($paysafeApiKeyId, $paysafeApiKeySecret, $mode, $paysafeAccountNumber);
                $this->client = $client;
            } catch (\Exception $e) {
                throw new LocalizedException(__('Can not instance Paysafe SDK'));
            }
        }

        return $this->client;
    }
}