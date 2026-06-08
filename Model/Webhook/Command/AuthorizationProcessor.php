<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\PaymentStateManager;

class AuthorizationProcessor extends AbstractProcessor
{
    /**
     * @var OrderSender
     */
    private $orderSender;
    
    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var PaymentStateManager
     */
    private $paymentStateManager;

    /**
     * @param \Straumur\Payment\Api\Webhook\ValidatorInterface $validator
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Psr\Log\LoggerInterface $logger
     * @param OrderSender $orderSender
     * @param StraumurHelper $straumurHelper
     * @param TransactionCalculator $transactionCalculator
     * @param PaymentStateManager $paymentStateManager
     */
    public function __construct(
        \Straumur\Payment\Api\Webhook\ValidatorInterface $validator,
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Psr\Log\LoggerInterface $logger,
        OrderSender $orderSender,
        StraumurHelper $straumurHelper,
        TransactionCalculator $transactionCalculator,
        PaymentStateManager $paymentStateManager
    ) {
        parent::__construct($validator, $gatewayConfig, $orderRepository, $searchCriteriaBuilder, $logger);
        $this->orderSender = $orderSender;
        $this->straumurHelper = $straumurHelper;
        $this->transactionCalculator = $transactionCalculator;
        $this->paymentStateManager = $paymentStateManager;
    }
    /**
     * @inheritDoc
     */
    protected function processWebhook(array $payload, OrderInterface $order): array
    {
        // Handle both lowercase and uppercase field names
        $successValue = $payload['success'] ?? $payload['Success'] ?? 'false';
        $success = ($successValue === 'true' || $successValue === true);
        $payfacReference = $payload['payfacReference'] ?? $payload['PayfacReference'] ?? '';
        $amountRaw = $payload['amount'] ?? $payload['Amount'] ?? null;
        $amount = $amountRaw !== null ? (float)$amountRaw : null;
        
        if ($success) {
            // Check if order is already authorized (idempotency check)
            $payment = $order->getPayment();
            $alreadyAuthorized = $payment->getAdditionalInformation(PaymentStateManager::FLAG_PAYMENT_AUTHORIZED);
            
            // Don't count false positives from session creation
            $sessionCreated = $payment->getAdditionalInformation('straumur_session_created');
            if ($sessionCreated && $alreadyAuthorized === false) {
                $alreadyAuthorized = false;  // Session was created but payment not actually authorized
            }
            
            if ($alreadyAuthorized) {
                // Order already authorized - this is likely a duplicate webhook
                // Log to debug only - no order comment needed for duplicates
                $this->logger->info('Duplicate authorization webhook ignored (idempotency check)', [
                    'order_id' => $order->getIncrementId(),
                    'order_state' => $order->getState(),
                    'payfac_reference' => $payfacReference,
                    'existing_reference' => $payment->getAdditionalInformation('straumur_payfac_reference'),
                    'amount' => $amount
                ]);

                return [
                    'status' => 'success',
                    'event_type' => 'authorization',
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId(),
                    'transaction_id' => $payfacReference,
                    'amount' => $amount,
                    'order_state' => $order->getState(),
                    'order_status' => $order->getStatus(),
                    'note' => 'Duplicate authorization webhook ignored'
                ];
            }
            
            // Validate order can be authorized
            if (!$this->canAuthorizeOrder($order)) {
                throw new \InvalidArgumentException(
                    sprintf('Order %s cannot be authorized in current state: %s', 
                        $order->getIncrementId(), $order->getState())
                );
            }
            
            // Add to transaction history (new multi-transaction tracking)
            $this->transactionCalculator->addTransactionToHistory($payment, 'authorizations', [
                'amount' => $amount ?? $order->getGrandTotal() * 100, // Convert to minor units if not provided
                'reference' => $payfacReference,
                'date' => date('Y-m-d H:i:s'),
                'success' => true
            ]);
            
            // Extract additional data from webhook
            $additionalData = $payload['additionalData'] ?? [];
            
            // Use PaymentStateManager for state-aware processing
            $wasMovedToProcessing = $this->paymentStateManager->markPaymentAuthorized(
                $order, 
                $payfacReference, 
                $amount,
                $additionalData
            );
            
            $this->logger->info('Payment authorization webhook processed', [
                'order_id' => $order->getIncrementId(),
                'payfac_reference' => $payfacReference,
                'moved_to_processing' => $wasMovedToProcessing,
                'customer_returned' => $this->paymentStateManager->hasCustomerReturned($order)
            ]);
            
        } else {
            // Authorization failed - cancel order
            if ($order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(
                    __('Payment authorization failed via webhook - Order cancelled'),
                    false,
                    true
                );
            } else {
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
                $order->setStatus('canceled');
                $order->addCommentToStatusHistory(
                    __('Payment authorization failed via webhook'),
                    false,
                    true
                );
            }
            
            $this->orderRepository->save($order);
        }

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'authorization',
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'transaction_id' => $payfacReference,
            'amount' => $amount,
            'order_state' => $order->getState(),
            'order_status' => $order->getStatus()
        ];
    }

    /**
     * Check if order can be authorized
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function canAuthorizeOrder(OrderInterface $order): bool
    {
        // Allow authorization for orders in these states
        return in_array($order->getState(), [
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW,  // Magento may put order in payment_review
            \Magento\Sales\Model\Order::STATE_PROCESSING  // Allow processing state for edge cases
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'authorization';
    }
}