<?php

namespace Dibs\EasyCheckout\Controller\Order;

use Dibs\EasyCheckout\Controller\Checkout;
use Dibs\EasyCheckout\Model\Checkout as DibsCheckout;
use Dibs\EasyCheckout\Model\CheckoutContext as DibsCheckoutCOntext;
use Dibs\EasyCheckout\Model\Client\DTO\Payment\CreatePaymentWebhook;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Model\Quote;
use Dibs\EasyCheckout\Logger\Logger;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class WebhookCallback extends Checkout
{
    /** @var Logger */
    protected $logger;


    /** @var \Magento\Quote\Model\QuoteFactory $quoteFactory */
    protected $quoteFactory;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $jsonResultFactory;



    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $accountManagement,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        DibsCheckout $dibsCheckout,
        DibsCheckoutCOntext $dibsCheckoutContext,
        Logger $logger,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->jsonResultFactory = $jsonResultFactory;

        parent::__construct(
            $context,
            $customerSession,
            $customerRepository,
            $accountManagement,
            $checkoutSession,
            $storeManager,
            $resultPageFactory,
            $dibsCheckout,
            $dibsCheckoutContext
        );
    }

    public function execute()
    {
        $quoteId = $this->getRequest()->getParam('qid');
        $data = json_decode($this->getRequest()->getContent(), true);
        $result = $this->jsonResultFactory->create();
        $result->setData([]);


        if (!isset($data['event']) || $data['event'] !== CreatePaymentWebhook::EVENT_PAYMENT_CHECKOUT_COMPLETED || !isset($data['data']['paymentId'])){
            $this->logger->info(
                'Webhook skip',
                [
                    'event' => $data['event'] ?? null,
                    'payment_id' => $data['data']['paymentId'] ?? null,
                    'quote_id' => $quoteId,
                    'response_code' => 200,
                ]
            );
            $result->setHttpResponseCode(200);
            return $result;
        }

        $paymentId = $data['data']['paymentId'];

        $logContext = [
            'payment_id' => $paymentId,
            'quote_id' => $quoteId,
        ];

        $checkout = $this->getDibsCheckout();
        $checkout->setCheckoutContext($this->dibsCheckoutContext);


        // validate authorization
        $ourSecret = $checkout->getHelper()->getWebhookSecret();
        if ($ourSecret && $ourSecret != $this->getRequest()->getHeader("Authorization")) {
            $this->logger->warning('Webhook error - bad secret', $logContext + ['response_code' => 401]);
            $result->setHttpResponseCode(401);
            return $result;
        }

        try {
            $quote = $this->loadQuote($quoteId);
        } catch (\Exception $e) {
            $this->logger->error('Webhook error - cannot load quote - ' . $e->getMessage(), $logContext + ['response_code' => 500]);
            $this->logger->error($e);

            // maybe magento is down?
            $result->setHttpResponseCode(500);
            return $result;
        }

        try {
            $dibsPayment = $checkout->getDibsPaymentHandler()->loadDibsPaymentById($paymentId);
        } catch (\Exception $e) {
            $this->logger->error("Webhook error - Could not load dibs payment - " . $e->getMessage(), $logContext + ['response_code' => 500]);
            $this->logger->error($e);

            // maybe nets is down
            $result->setHttpResponseCode(500);
            return $result;
        }

        // we check that its the correct quote
        if ($checkout->getDibsPaymentHandler()->generateReferenceByQuoteId($quoteId) !== $dibsPayment->getOrderDetails()->getReference()) {
            $this->logger->error('Webhook error - reference mismatch', $logContext + [
                'reference' => $dibsPayment->getOrderDetails()->getReference(),
                'response_code' => 200,
            ]);
            // either its wrong, or order has been placed already! (since we update reference when order is placed to magento order id)
            $result->setHttpResponseCode(200);
            return $result;
        }

        $weHandleConsumerData = false;
        $changeUrl = true;
        if ($this->dibsCheckoutContext->getHelper()->getCheckoutUrl() !== $dibsPayment->getCheckoutUrl()) {
            $weHandleConsumerData = true;
            $changeUrl = false;
        }

        // HOWERE if quote is virtual, we let them handle consumer data, since we dont add these fields in our checkout!
        if ($quote->isVirtual()) {
            $weHandleConsumerData = false;
        }



        // OK the payment exists, payment ids are matching... lets check no order has been placed
        $orderCollection = $this->dibsCheckoutContext->getOrderCollectionFactory()->create();
        $ordersCount = $orderCollection
            ->addFieldToFilter('dibs_payment_id', ['eq' => $paymentId])
            ->load()
            ->count();

        if ($ordersCount > 0) {
            $this->logger->info('Webhook skip - order already created', $logContext + ['response_code' => 200]);
            $result->setHttpResponseCode(200);
            return $result;
        }

        $this->logger->info('Webhook - placing order', $logContext);
        try {
            $order = $checkout->placeOrder($dibsPayment, $quote, $weHandleConsumerData, false);
        } catch (\Exception $e) {
            $this->logger->error(
                "Webhook error - Could not place order  - " . $e->getMessage(),
                $logContext + [
                    'trace' => (string)$e,
                    'response_code' => 500,
                ]
            );


            $result->setHttpResponseCode(500);
            return $result;
        }

        $logContext['order_id'] = $order->getId();
        $logContext['order_increment'] = $order->getIncrementId();

        try {
            $this->logger->info('Webhook - updating payment reference', $logContext);
            $checkout->getDibsPaymentHandler()->updateMagentoPaymentReference($order, $paymentId, $changeUrl);
        } catch (\Exception $e) {
            $this->logger->error(
                'Webhook error - cannot update payment reference - ' . $e->getMessage(),
                $logContext + ['response_code' => 200]
            );

            // lets ignore this and save it in logs! let customer see his/her order confirmation!
            // ... ignore this error...
        }

        $this->logger->info('Webhook success', $logContext + ['response_code' => 200]);

        $result->setHttpResponseCode(200);
        return $result;
    }

    /**
     * @param $quoteId
     * @return Quote
     * @throws \Exception
     */
    protected function loadQuoteById($quoteId)
    {
        return $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
    }


    /**
     * @param $quoteId int
     * @return Quote|void
     * @throws \Exception
     */
    protected function loadQuote($quoteId)
    {
//        try {
            $quote = $this->loadQuoteById($quoteId);
//        } catch (\Exception $e) {
//            $this->logger->error("Webhook: We found no quote for this Nets Payment.");
//            throw new \Exception("Found no quote object for this Nets Payment ID.");
//        }

        return $quote;
    }

}
