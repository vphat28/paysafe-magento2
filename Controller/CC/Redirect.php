<?php

namespace Paysafe\Payment\Controller\Cc;

use Magento\Framework\App\Action\Action;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;

class Redirect extends Action
{
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

    public function __construct(
        Context $context,
        Session $checkoutSession,
        UrlInterface $url
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->url = $url;
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
        $redirectUrl = $payment->getAdditionalInformation('3ds_redirect_url');

        if (@!empty($redirectUrl)) {
            $url = $redirectUrl . '?' . http_build_query(['PaReq'=> $payment->getAdditionalInformation('paysafe_pareq'), 'TermUrl' => $this->url->getUrl('paysafe/cc/callback')]);
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultPage->setUrl($url);

            return $resultPage;
        }


        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        $resultPage->setHttpResponseCode(404);

        return $resultPage;
    }
}