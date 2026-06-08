<?php
declare(strict_types=1);

namespace Straumur\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;
use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Model\Service\SessionManager;
use Straumur\Payment\Model\Service\PaymentStateManager;
use Psr\Log\LoggerInterface;

class ReturnAction implements HttpGetActionInterface
{
    /**
     * @var RequestInterface.
     */
    private $request;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var StraumurApiInterface
     */
    private $straumurApi;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @var PaymentStateManager
     */
    private $paymentStateManager;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messageManager
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CheckoutSession $checkoutSession
     * @param StraumurApiInterface $straumurApi
     * @param SessionManager $sessionManager
     * @param PaymentStateManager $paymentStateManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CheckoutSession $checkoutSession,
        StraumurApiInterface $straumurApi,
        SessionManager $sessionManager,
        PaymentStateManager $paymentStateManager,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->checkoutSession = $checkoutSession;
        $this->straumurApi = $straumurApi;
        $this->sessionManager = $sessionManager;
        $this->paymentStateManager = $paymentStateManager;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        
        try {
            // Check for both session_id (primary) and reference (fallback) parameters
            $sessionToken = $this->request->getParam('session_id');
            $orderReference = $this->request->getParam('reference');
            
            // If we have a session token, use the secure session flow
            if (!empty($sessionToken)) {
                return $this->handleSecureSessionReturn($sessionToken, $redirect);
            }
            
            // If we have an order reference but no session token, use direct order lookup
            if (!empty($orderReference)) {
                return $this->handleOrderReferenceReturn($orderReference, $redirect);
            }
            
            // No valid parameters provided
            $this->logger->warning('Payment return with no valid parameters');
            $this->messageManager->addErrorMessage(__('Invalid payment return - missing required parameters.'));
            return $redirect->setPath('checkout/cart');
            
        } catch (\Exception $e) {
            $this->logger->error('Payment return processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->messageManager->addErrorMessage(__('An error occurred processing your payment return.'));
            return $redirect->setPath('checkout/cart');
        }
    }
    
    /**
     * Handle return with secure session token
     *
     * @param string $sessionToken
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @return ResultInterface
     */
    private function handleSecureSessionReturn(string $sessionToken, $redirect): ResultInterface
    {
        // Validate session token and get checkout reference
        $sessionData = $this->sessionManager->validateSession($sessionToken);
        
        if (!$sessionData) {
            $this->logger->warning('Payment return with invalid/expired session token', [
                'session_token' => $sessionToken
            ]);
            $this->messageManager->addErrorMessage(__('Payment session has expired. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        $checkoutReference = $sessionData['checkout_reference'];
        $this->logger->info('Processing secure payment return', [
            'session_token' => $sessionToken,
            'checkout_reference' => $checkoutReference
        ]);

        // Find order by checkout reference
        $order = $this->findOrderByCheckoutReference($checkoutReference);
        
        if (!$order) {
            $this->logger->error('Order not found for validated session', [
                'checkout_reference' => $checkoutReference,
                'session_token' => $sessionToken
            ]);
            $this->messageManager->addErrorMessage(__('Order not found for this payment session.'));
            return $redirect->setPath('checkout/cart');
        }
        
        return $this->processPaymentStatus($order, $checkoutReference, $redirect);
    }
    
    /**
     * Handle return with order reference parameter
     *
     * @param string $orderReference
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @return ResultInterface
     */
    private function handleOrderReferenceReturn(string $orderReference, $redirect): ResultInterface
    {
        $this->logger->info('Processing payment return with order reference', [
            'order_reference' => $orderReference
        ]);
        
        // Find order by increment ID
        $order = $this->findOrderByIncrementId($orderReference);
        
        if (!$order) {
            $this->logger->error('Order not found for reference', [
                'order_reference' => $orderReference
            ]);
            $this->messageManager->addErrorMessage(__('Order not found.'));
            return $redirect->setPath('checkout/cart');
        }
        
        // Get checkout reference from payment
        $payment = $order->getPayment();
        $checkoutReference = $payment->getAdditionalInformation('straumur_checkout_reference');
        
        if (empty($checkoutReference)) {
            $this->logger->error('No checkout reference found for order', [
                'order_id' => $order->getId(),
                'order_reference' => $orderReference
            ]);
            $this->messageManager->addErrorMessage(__('Payment session not found for this order.'));
            return $redirect->setPath('checkout/cart');
        }
        
        return $this->processPaymentStatus($order, $checkoutReference, $redirect);
    }
    
    /**
     * Process payment status and handle response
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param string $checkoutReference
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @return ResultInterface
     */
    private function processPaymentStatus($order, string $checkoutReference, $redirect): ResultInterface
    {
        // Don't check payment status on return - this can trigger payment_review state
        // Just mark customer as returned and let webhook handle authorization status
        $wasMovedToProcessing = $this->paymentStateManager->markCustomerReturned($order);
        
        // Set up checkout session for success page
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        
        $this->logger->info('Customer return processed successfully', [
            'order_id' => $order->getIncrementId(),
            'moved_to_processing' => $wasMovedToProcessing,
            'payment_authorized' => $this->paymentStateManager->isPaymentAuthorized($order),
            'checkout_reference' => $checkoutReference
        ]);
        
        $this->messageManager->addSuccessMessage(
            __('Thank you! Your payment has been processed successfully. You\'ll receive an order confirmation email within the next few minutes.')
        );
        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * Find order by checkout reference
     *
     * @param string $checkoutReference
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function findOrderByCheckoutReference(string $checkoutReference)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('state', \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        foreach ($orders as $order) {
            $payment = $order->getPayment();
            $storedReference = $payment->getAdditionalInformation('straumur_checkout_reference');
            
            if ($storedReference === $checkoutReference) {
                return $order;
            }
        }

        return null;
    }
    
    /**
     * Find order by increment ID
     *
     * @param string $incrementId
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function findOrderByIncrementId(string $incrementId)
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->create();

            $orders = $this->orderRepository->getList($searchCriteria)->getItems();

            if (count($orders) > 0) {
                return reset($orders);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error finding order by increment ID', [
                'increment_id' => $incrementId,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}
