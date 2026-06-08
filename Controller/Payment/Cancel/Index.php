<?php
declare(strict_types=1);

namespace Straumur\Payment\Controller\Payment\Cancel;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;
use Straumur\Payment\Model\Service\SessionManager;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    /**
     * @var RequestInterface
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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messageManager
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CheckoutSession $checkoutSession
     * @param SessionManager $sessionManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CheckoutSession $checkoutSession,
        SessionManager $sessionManager,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->checkoutSession = $checkoutSession;
        $this->sessionManager = $sessionManager;
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
                return $this->handleSecureSessionCancel($sessionToken, $redirect);
            }
            
            // If we have an order reference but no session token, use direct order lookup
            if (!empty($orderReference)) {
                return $this->handleOrderReferenceCancel($orderReference, $redirect);
            }
            
            // No valid parameters provided - user likely cancelled without order
            $this->logger->warning('Payment cancel with no valid parameters');
            $this->messageManager->addNoticeMessage(__('Payment was cancelled. You can try again or choose a different payment method.'));
            return $redirect->setPath('checkout/cart');

        } catch (\Exception $e) {
            $this->logger->error('Payment cancel processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->messageManager->addNoticeMessage(__('Payment was cancelled. You can try again or choose a different payment method.'));
            return $redirect->setPath('checkout/cart');
        }
    }
    
    /**
     * Handle cancel with secure session token
     *
     * @param string $sessionToken
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @return ResultInterface
     */
    private function handleSecureSessionCancel(string $sessionToken, $redirect): ResultInterface
    {
        // Validate session token and get checkout reference
        $sessionData = $this->sessionManager->validateSession($sessionToken);
        
        if (!$sessionData) {
            $this->logger->warning('Payment abandon with invalid/expired session token', [
                'session_token' => $sessionToken
            ]);
            $this->messageManager->addNoticeMessage(__('Payment session expired. You can start a new payment.'));
            return $redirect->setPath('checkout/cart');
        }

        $checkoutReference = $sessionData['checkout_reference'];
        $this->logger->info('Processing secure payment abandon', [
            'session_token' => $sessionToken,
            'checkout_reference' => $checkoutReference
        ]);

        // Find order by checkout reference
        $order = $this->findOrderByCheckoutReference($checkoutReference);
        
        if ($order) {
            $this->processOrderCancel($order, $checkoutReference);
        } else {
            $this->logger->warning('Order not found for abandon session', [
                'checkout_reference' => $checkoutReference,
                'session_token' => $sessionToken
            ]);
        }

        // User-friendly message with retry option
        $this->messageManager->addNoticeMessage(
            __('Payment was cancelled. Your session is still active - you can complete the payment or choose a different payment method.')
        );
        return $redirect->setPath('checkout/cart');
    }
    
    /**
     * Handle cancel with order reference parameter
     *
     * @param string $orderReference
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @return ResultInterface
     */
    private function handleOrderReferenceCancel(string $orderReference, $redirect): ResultInterface
    {
        $this->logger->info('Processing payment cancellation with order reference', [
            'order_reference' => $orderReference
        ]);
        
        // Find order by increment ID
        $order = $this->findOrderByIncrementId($orderReference);
        
        if (!$order) {
            $this->logger->warning('Order not found for cancelled payment', [
                'order_reference' => $orderReference
            ]);
            // Don't show error to customer, just redirect to cart
            $this->messageManager->addNoticeMessage(__('Payment was cancelled.'));
            return $redirect->setPath('checkout/cart');
        }
        
        // Get checkout reference from payment
        $payment = $order->getPayment();
        $checkoutReference = $payment->getAdditionalInformation('straumur_checkout_reference');
        
        if (empty($checkoutReference)) {
            $this->logger->warning('No checkout reference found for cancelled order', [
                'order_id' => $order->getId(),
                'order_reference' => $orderReference
            ]);
        }
        
        $this->processOrderCancel($order, $checkoutReference ?: 'unknown');
        
        $this->messageManager->addNoticeMessage(
            __('Payment was cancelled. You can try again or choose a different payment method.')
        );
        return $redirect->setPath('checkout/cart');
    }
    
    /**
     * Process order cancellation
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param string $checkoutReference
     */
    private function processOrderCancel($order, string $checkoutReference): void
    {
        // DO NOT cancel the order immediately - session remains active until expiry
        // per Straumur documentation: "Session remains active (status: 'New') until expiry"
        $order->addCommentToStatusHistory(
            __('Customer abandoned payment page. Session remains active until expiry. Customer can retry payment.')
        );
        $this->orderRepository->save($order);
        
        // Restore quote for customer to retry
        $this->checkoutSession->restoreQuote();
        
        $this->logger->info('Payment abandon processed, session kept active', [
            'order_id' => $order->getId(),
            'checkout_reference' => $checkoutReference
        ]);
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