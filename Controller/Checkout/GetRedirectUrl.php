<?php
declare(strict_types=1);

namespace Straumur\Payment\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class GetRedirectUrl implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * Get redirect URL for Straumur checkout
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $orderId = $this->checkoutSession->getLastOrderId();
            if (!$orderId) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Order not found')
                ]);
            }

            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();

            if ($payment->getMethod() !== 'straumur_payment') {
                return $result->setData([
                    'success' => false,
                    'message' => __('Invalid payment method')
                ]);
            }

            $checkoutUrl = $payment->getAdditionalInformation('straumur_checkout_url');
            if (!$checkoutUrl) {
                // Debug: Log all available payment information
                $this->logger->error('Checkout URL not found in payment additional information', [
                    'order_id' => $order->getIncrementId(),
                    'payment_method' => $payment->getMethod(),
                    'order_state' => $order->getState(),
                    'order_status' => $order->getStatus(),
                    'payment_additional_info' => $payment->getAdditionalInformation(),
                    'transaction_id' => $payment->getTransactionId()
                ]);
                
                return $result->setData([
                    'success' => false,
                    'message' => __('Checkout URL not found. Please check the logs for more details.')
                ]);
            }

            $this->logger->info('Straumur GetRedirectUrl', [
                'order_id' => $order->getIncrementId(),
                'checkout_url' => $checkoutUrl
            ]);

            return $result->setData([
                'success' => true,
                'redirect_url' => $checkoutUrl
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Straumur GetRedirectUrl Error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
