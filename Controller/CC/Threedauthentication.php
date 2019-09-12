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

class Threedauthentication extends Action
{
    /** @var Data */
    private $helper;

    /** @var ClientInterfaceFactory */
    private $clientInterfaceFactory;

    /** @var QuoteRepository */
    private $quoteRepository;

    public function __construct(
        Context $context,
        Data $helper,
        UrlInterface $url,
        QuoteRepository $quoteRepository,
        ClientFactory $clientInterfaceFactory
    )
    {
        $this->quoteRepository = $quoteRepository;
        $this->url = $url;
        $this->helper = $helper;
        $this->clientInterfaceFactory = $clientInterfaceFactory;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     */
    public function execute()
    {
        $request = $this->getRequest()->getParams();

        if ($this->helper->isTestMode()) {
            $url = 'https://api.test.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        } else {
            $url = 'https://api.paysafe.com/threedsecure/v2/accounts/' . $this->helper->getAccountNumber() . '/authentications';
        }

        /** @var \Magento\Framework\Controller\Result\Json $json */
        $json = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        $jsonResult = [];
        $quote = $this->quoteRepository->get($_SESSION["checkout"]["quote_id_1"]);
        $billingAddress = $quote->getBillingAddress();
        /** @var ClientInterface $client */
        $client = $this->clientInterfaceFactory->create();
        $options = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->helper->getSingleUseToken()),
            ],
            'json' => [
                'amount' => $quote->getGrandTotal() * 100,
                'currency' => $quote->getQuoteCurrencyCode(),
                'merchantRefNum' => $quote->getQuoteCurrencyCode() . '-' . $quote->getId() . time(),
                'merchantUrl' => $this->url->getBaseUrl(),
                'card' => [
                    "cardExpiry" => [
                        "month" => $request['card']['cardExpiry']['month'],
                        "year" => $request['card']['cardExpiry']['year']
                    ],
                    "cardNum" => $request['card']['cardNum'],
                    "holderName" => $billingAddress->getFirstname() . ' ' . $billingAddress->getLastname(),
                ],
                'deviceFingerprintingId' => $request['deviceFingerprintingId'],
                'deviceChannel' => 'BROWSER',
                'messageCategory' => 'PAYMENT',
                'authenticationPurpose' => 'PAYMENT_TRANSACTION',
            ],
        ];
        try {
            $response = $client->request('POST', $url, $options);
        } catch (\Exception $exception) {
            $response = $exception->getResponse();
        }

        $bodyContent = $response->getBody()->getContents();
        $object = json_decode($bodyContent, true);

        if (isset($object["threeDResult"]) &&
            $object["status"] === 'COMPLETED' &&
            $object["threeDResult"] === 'Y' &&
            version_compare($object['threeDSecureVersion'], '2.0') >= 0
        ) {
            /** @var Client $client */
            $client = $this->clientInterfaceFactory->create();
            $options = [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->helper->getAPIUsername() . ':' . $this->helper->getAPIPassword()),
                ],
                'json' => []
            ];
            $options['json'] = [
                'merchantRefNum' => $quote->getQuoteCurrencyCode() . '-' . $quote->getId() . time(),
                "amount" => $quote->getGrandTotal() * 100,
                "settleWithAuth" => true,
                "billingDetails" => [
                    "zip" => $billingAddress->getPostcode()
                ],
                'card' => [
                    "cardExpiry" => [
                        "month" => $request['card']['cardExpiry']['month'],
                        "year" => $request['card']['cardExpiry']['year']
                    ],
                    "cardNum" => $request['card']['cardNum'],
                ],
                "authentication" => [
                    "eci" => $object["eci"],
                    "cavv" => $object["cavv"],
                    "threeDResult" => "Y",
                    "threeDSecureVersion" => "2.1.0",
                ],
            ];
            // 3ds 2 completed, authorize payment
            if ($this->helper->isTestMode()) {
                $url = 'https://api.test.paysafe.com/cardpayments/v1/accounts/' . $this->helper->getAccountNumber() . '/auths';
            } else {
                $url = 'https://api.paysafe.com/cardpayments/v1/accounts/' . $this->helper->getAccountNumber() . '/auths';
            }

            try {
                $response = $client->request('POST', $url, $options);
                $response = json_decode($response->getBody()->getContents());
                $jsonResult = new \stdClass();
                $jsonResult->status = 'threed2completed';
                $jsonResult->dataLoad = $response;
            } catch (\Exception $exception) {
                $response = $exception->getResponse();
                $response = $response->getBody()->getContents();
            }
        }

        $json->setData(json_encode($jsonResult));

        return $json;
    }
}