<?php

namespace Paysafe\Payment\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Data
{
    private $scopeConfig;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function isPaysafeActive($store = null)
    {
        return $this->scopeConfig->isSetFlag('payment/paysafe_gateway/active', ScopeInterface::SCOPE_STORE, $store);
    }

    public function isEnableAccordD($store = null)
    {
        return $this->scopeConfig->isSetFlag('payment/paysafe_gateway/enable_accordD', ScopeInterface::SCOPE_STORE, $store);
    }

    public function getCurrencyMultiplier($currencyCode)
    {
        return 100;
    }

    public function isTestMode($store = null)
    {
        return $this->scopeConfig->isSetFlag('payment/paysafe_gateway/test_mode', ScopeInterface::SCOPE_STORE, $store);
    }

    public function threedsecureMode($store = null)
    {
        return (int)$this->scopeConfig->getValue('payment/paysafe_gateway/threedsecure', ScopeInterface::SCOPE_STORE, $store);
    }

    public function getAccountNumber($store = null)
    {
        return $this->scopeConfig->getValue('payment/paysafe_gateway/account_number', ScopeInterface::SCOPE_STORE, $store);
    }

    public function getPaymentAction($store = null)
    {
        return $this->scopeConfig->getValue('payment/paysafe_gateway/payment_action', ScopeInterface::SCOPE_STORE, $store);
    }

    public function getCCTypes($store = null)
    {
        return $this->scopeConfig->getValue('payment/paysafe_gateway/cctypes', ScopeInterface::SCOPE_STORE, $store);
    }

    public function getAPIUsername($store = null)
    {
        return $this->encryptor->decrypt($this->scopeConfig->getValue('payment/paysafe_gateway/api_username', ScopeInterface::SCOPE_STORE, $store));
    }

    public function getAPIPassword($store = null)
    {
        return $this->encryptor->decrypt($this->scopeConfig->getValue('payment/paysafe_gateway/api_password', ScopeInterface::SCOPE_STORE, $store));
    }

    public function getSingleUseToken($store = null)
    {
        return $this->encryptor->decrypt($this->scopeConfig->getValue('payment/paysafe_gateway/single_use_token', ScopeInterface::SCOPE_STORE, $store));
    }

    public function initPaysafeSDK()
    {
        require_once(dirname(__FILE__, 2) . '/SDK/paysafe.php');
    }
}