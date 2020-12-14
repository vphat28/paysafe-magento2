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

class Threedauthentication extends Action {
    /** @var Data */
    private $helper;

    /** @var ClientInterfaceFactory */
    private $clientInterfaceFactory;

    /** @var QuoteRepository */
    private $quoteRepository;
    /** @var \Magento\Checkout\Model\Session */
    private $checkoutSession;

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
        $request = $this->getRequest()->getParams();

        if ($this->helper->isTestMode()) {
            $url = 'https://api.test.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        } else {
            $url = 'https://api.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        }

        /** @var \Magento\Framework\Controller\Result\Json $json */
        $json           = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        $jsonResult     = [];
        $quote          = $this->checkoutSession->getQuote();
        $billingAddress = $quote->getBillingAddress();
        /** @var ClientInterface $client */
        $client   = $this->clientInterfaceFactory->create();
        $options  = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->helper->getSingleUseToken()),
            ],
            'json'    => [
                'amount'                 => $quote->getGrandTotal() * 100,
                'currency'               => $quote->getQuoteCurrencyCode(),
                'merchantRefNum'         => $quote->getQuoteCurrencyCode() . '-' . $quote->getId() . '-' . time(),
                'merchantUrl'            => $this->url->getBaseUrl(),
                'card'                   => [
                    "cardExpiry" => [
                        "month" => $request['card']['cardExpiry']['month'],
                        "year"  => $request['card']['cardExpiry']['year']
                    ],
                    "cardNum"    => $request['card']['cardNum'],
                    "holderName" => $billingAddress->getFirstname() . ' ' . $billingAddress->getLastname(),
                ],
                'deviceFingerprintingId' => $request['deviceFingerprintingId'],
                'deviceChannel'          => 'BROWSER',
                'messageCategory'        => 'PAYMENT',
                'authenticationPurpose'  => 'PAYMENT_TRANSACTION',
            ],
        ];
        $response = $client->request('POST', $url, $options);


        $bodyContent = $response->getBody()->getContents();
        $object      = json_decode($bodyContent, true);

        $this->logger->debug('Got 3ds authentication response', $object);

        if (isset($object["threeDResult"]) &&
            $object["status"] === 'COMPLETED' &&
            version_compare($object['threeDSecureVersion'], '2.0') >= 0
        ) {
            /** @var Client $client */
            $client          = $this->clientInterfaceFactory->create();
            $authHeader      = 'Basic ' . base64_encode($this->helper->getAPIUsername() . ':' . $this->helper->getAPIPassword());
            $options         = [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->helper->getAPIUsername() . ':' . $this->helper->getAPIPassword()),
                ],
                'json'    => []
            ];
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
                    "eci"                 => $object["eci"], 
                    "threeDResult"        => "Y",
                    "threeDSecureVersion" => "2.1.0",
                ],
            ];



            if (isset($object['cavv'])) {
                $options['json']['authentication']['cavv'] = $object['cavv'];
            }

            $options['json']['authentication']['id'] = $object['id'];

            $jsonResult           = new \stdClass();
            $jsonResult->status   = 'threed2completed';
            $jsonResult->dataLoad = $options['json']['authentication'];

        }

        if (
            $object["status"] === 'COMPLETED' &&
            version_compare($object['threeDSecureVersion'], '2.0') < 0
        ) {
            $jsonResult           = new \stdClass();
            $jsonResult->status   = 'threed2completed';
            $jsonResult->dataLoad = [
                'id' => $object['id']
            ];
        }

        if (
            $object["status"] === 'PENDING' &&
            version_compare($object['threeDSecureVersion'], '2.0') < 0 &&
            $object["threeDEnrollment"] == 'Y'
        ) {

            $jsonResult = new \stdClass();
            $jsonResult->status   = 'threed2pending';
            $jsonResult->three_d_auth = $object;
        }

        if (isset($object["threeDResult"]) &&
            $object["status"] === 'PENDING' &&
            $object["threeDResult"] === 'C' &&
            version_compare($object['threeDSecureVersion'], '2.0') >= 0) {
            $jsonResult = new \stdClass();
            $jsonResult->status   = 'threed2pending';
            $jsonResult->three_d_auth = $object;
        }

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
}
