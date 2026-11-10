<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\PaymentStateManager;
use Straumur\Payment\Model\Webhook\WebhookPayload;

class AuthorizationProcessor extends AbstractProcessor
{
    /**
     * States of an order that is still waiting for its payment.
     */
    private const PRE_PAYMENT_STATES = [
        \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
        \Magento\Sales\Model\Order::STATE_NEW,
        \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW, // Magento may put order in payment_review
    ];

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
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param OrderSender $orderSender
     * @param StraumurHelper $straumurHelper
     * @param TransactionCalculator $transactionCalculator
     * @param PaymentStateManager $paymentStateManager
     */
    public function __construct(
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger,
        OrderSender $orderSender,
        StraumurHelper $straumurHelper,
        TransactionCalculator $transactionCalculator,
        PaymentStateManager $paymentStateManager
    ) {
        parent::__construct($gatewayConfig, $orderRepository, $logger);
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
        $success = $this->isSuccess($payload);
        $payfacReference = WebhookPayload::string($payload, 'payfacReference');
        $amountRaw = WebhookPayload::field($payload, 'amount');
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
            
        } elseif (!$this->isAwaitingPayment($order)) {
            // A declined attempt (for example a second card on a payment link) must not cancel an order
            // that is already authorized or paid.
            $order->addCommentToStatusHistory(
                __('A Straumur payment attempt for this order was declined; the order was not changed.'),
                false,
                false
            );
            $this->orderRepository->save($order);

            $this->logger->warning('Declined authorization for an order that is not awaiting payment; not cancelled', [
                'order_id' => $order->getIncrementId(),
                'order_state' => $order->getState(),
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
     * Whether the order is still waiting for its payment: not yet authorized and in a pre-payment state.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function isAwaitingPayment(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        if ($payment !== null && $payment->getAdditionalInformation(PaymentStateManager::FLAG_PAYMENT_AUTHORIZED)) {
            return false;
        }

        return in_array($order->getState(), self::PRE_PAYMENT_STATES, true);
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
        // Processing is allowed too, for edge cases.
        return in_array(
            $order->getState(),
            array_merge(self::PRE_PAYMENT_STATES, [\Magento\Sales\Model\Order::STATE_PROCESSING]),
            true
        );
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'authorization';
    }
}