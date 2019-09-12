<?php

namespace Paysafe\Payment\Controller\Cc;

use Magento\Framework\App\Action\Action;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Paysafe\CardPayments\Authorization;
use Paysafe\Payment\Helper\Data;
use Paysafe\Payment\Model\PaysafeClient;
use Paysafe\ThreeDSecure\Authentications;

class Callback extends Action
{
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;
    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /** @var UrlInterface */
    private $url;

    /** @var Data */
    private $data;

    /** @var PaysafeClient */
    private $paysafeClient;

    public function __construct(
        Context $context,
        Session $checkoutSession,
        UrlInterface $url,
        PaysafeClient $paysafeClient,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        Data $data
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->url = $url;
        $this->data = $data;
        $this->orderRepository = $orderRepository;
        $this->paysafeClient = $paysafeClient;
        if (interface_exists("\Magento\Framework\App\CsrfAwareActionInterface")) {
            $request = $this->getRequest();
            if ($request instanceof \Magento\Framework\App\Request\Http && $request->isPost() && empty($request->getParam('form_key'))) {
                $formKey = $this->_objectManager->get(\Magento\Framework\Data\Form\FormKey::class);
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     * @throws \Exception
     */
    public function execute()
    {
        /** @var Order $order */
        $order = $this->checkoutSession->getLastRealOrder();
        /** @var Order\Payment $payment */
        $payment = $order->getPayment();
        $enrollId = $payment->getAdditionalInformation('enrollcheck_id');
        $params = $this->getRequest()->getParams();
        $client = $this->paysafeClient->getClient();
        $hash = hash('crc32', $this->url->getBaseUrl());
        $response = $client->threeDSecureService()->authentications(new Authentications(array(
            'merchantRefNum' => $hash . time() . "-3dsauthentication",
            'paRes' => $params['PaRes'],
            'id' => $enrollId,
        )))->jsonSerialize();

        $authParams = json_decode($payment->getAdditionalInformation('auth_params'), true);
        $authParams['authentication'] = [
            "eci" => $response['eci'],
            "cavv" => $response['cavv'],
            "xid" => $response['xid'],
            "threeDEnrollment" => "Y",
            "threeDResult"=> "Y",
            "signatureStatus"=> "Y"
        ];

        $auth = $client->cardPaymentService()->authorize(new Authorization($authParams))->jsonSerialize();
        $payment->setAdditionalInformation('paysafe_txn_id', $auth['id']);
        try {
            $invoice = $this->invoice($payment);
            $order->getState('processing');
            $order->getStatus('processing');
            $payment->setAdditionalInformation('auth_params', '');
            $this->orderRepository->save($order);
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultPage->setUrl($this->url->getUrl('checkout/onepage/success'));
        } catch (\Exception $e) {
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_RAW);

            $resultPage->setHttpResponseCode(404);
        }

        return $resultPage;
    }

    protected function invoice(\Magento\Sales\Api\Data\OrderPaymentInterface $payment)
    {
        /** @var Order\Invoice $invoice */
        $invoice = $payment->getOrder()->prepareInvoice();

        $invoice->register();
        if ($payment->getMethodInstance()->canCapture()) {
            $invoice->capture();
        }

        $payment->getOrder()->addRelatedObject($invoice);
        return $invoice;
    }
}