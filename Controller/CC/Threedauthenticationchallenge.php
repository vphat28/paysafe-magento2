<?php

namespace Paysafe\Payment\Controller\Cc;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Client;
use Magento\Framework\App\Action\Action;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Model\Order;
use Paysafe\CardPayments\Authorization;
use Paysafe\Payment\Helper\Data;
use Paysafe\Payment\Model\PaysafeClient;
use Paysafe\ThreeDSecure\Authentications;

class Threedauthenticationchallenge extends Action {
    /** @var Data */
    private $helper;

    /** @var ClientInterfaceFactory */
    private $clientInterfaceFactory;

    /** @var \Magento\Checkout\Model\Session */
    private $checkoutSession;
    /** @var QuoteRepository */
    private $quoteRepository;

    public function __construct(
        Context $context,
        Data $helper,
        UrlInterface $url,
        QuoteRepository $quoteRepository,
        \Paysafe\Payment\Model\Logger $logger,
        \Magento\Checkout\Model\Session $checkoutSession,
        ClientFactory $clientInterfaceFactory
    ) {
        $this->quoteRepository        = $quoteRepository;
        $this->url                    = $url;
        $this->helper                 = $helper;
        $this->logger                 = $logger;
        $this->checkoutSession        = $checkoutSession;
        $this->clientInterfaceFactory = $clientInterfaceFactory;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     */
    public function execute() {
        $authID = $this->getRequest()->getParam('id');

        $request = $this->getRequest()->getParams();
        if ($this->helper->isTestMode()) {
            $url = 'https://api.test.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        } else {
            $url = 'https://api.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        }

        $header = 'Authorization: Basic ' . base64_encode($this->helper->getSingleUseToken());

        $authResponse = $this->simpleGet($url . '/' . $authID, $header);


        $this->logger->debug('3ds challenge result', $authResponse);

        // verify quote

        /** @var \Magento\Framework\Controller\Result\Json $json */
        $json       = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        $jsonResult = [];
        $quote = $this->checkoutSession->getQuote();

        $merchantRefNum = $authResponse['merchantRefNum'];
        $merchantRefNum = explode('-', $merchantRefNum);

        if (!isset($merchantRefNum[1]) || $merchantRefNum[1] != $quote->getId()) {
          return $json;
        }
        $billingAddress = $quote->getBillingAddress();
        $authHeader      = 'Basic ' . base64_encode($this->helper->getAPIUsername() . ':' . $this->helper->getAPIPassword());
        $options['json'] = [
            'merchantRefNum' => $quote->getQuoteCurrencyCode() . '-' . $quote->getId() . time(),
            "amount"         => $quote->getGrandTotal() * 100,
            "settleWithAuth" => true,
            "billingDetails" => [
                "zip" => $billingAddress->getPostcode()
            ],
            'card'           => [
                "cardExpiry" => [
                    "month" => $request['card']['cardExpiry']['month'],
                    "year"  => $request['card']['cardExpiry']['year']
                ],
                "cardNum"    => $request['card']['cardNum'],
            ],
            "authentication" => [
                "eci"                 => $authResponse["eci"],
                "threeDResult"        => "Y",
                "threeDSecureVersion" => "2.1.0",
            ],
        ];

        if (isset($authResponse['cavv'])) {
            $options['json']['authentication']['cavv'] = $authResponse['cavv'];
        }

        $jsonResult           = new \stdClass();
        $jsonResult->status   = 'threed2completed';
        $jsonResult->dataLoad = $options['json']['authentication'];
        $json->setData(json_encode($jsonResult));

        return $json;
    }

    public function simplePost($url, $header, $json) {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: ' . $header,
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return $response;

    }

    public function simpleGet($url, $auth) {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => array(
                $auth,
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response, true);
    }
}
