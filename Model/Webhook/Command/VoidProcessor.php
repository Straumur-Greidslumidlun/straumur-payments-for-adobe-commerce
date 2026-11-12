<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Webhook\WebhookPayload;

/**
 * Void/Reversal Webhook Processor
 *
 * Handles void operations on authorized-but-not-captured transactions.
 * Straumur sends eventType="Refund" for both void and refund operations,
 * so we distinguish by checking if order has any captured amounts.
 */
class VoidProcessor extends AbstractProcessor
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param TransactionCalculator $transactionCalculator
     */
    public function __construct(
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger,
        TransactionCalculator $transactionCalculator
    ) {
        parent::__construct($gatewayConfig, $orderRepository, $logger);
        $this->transactionCalculator = $transactionCalculator;
    }

    /**
     * Check if this processor can handle the webhook
     *
     * Handles webhooks with eventType="Refund" when the order has NO captured amounts
     * (i.e., void/reversal of authorization-only transactions)
     *
     * @param array $payload
     * @param OrderInterface $order
     * @return bool
     */
    public function canProcess(array $payload, OrderInterface $order): bool
    {
        if ($this->detectEventType($payload) !== 'refund') {
            return false;
        }

        return !$this->transactionCalculator->hasCapturedAmount($order);
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
        $reason = WebhookPayload::string($payload, 'reason') ?: 'Void authorization';

        $payment = $order->getPayment();

        if ($success) {
            // Verify order can be voided (should be in pending_payment or new state)
            if (!$this->canVoidOrder($order)) {
                throw new \InvalidArgumentException(
                    sprintf('Order %s cannot be voided in current state: %s',
                        $order->getIncrementId(), $order->getState())
                );
            }

            $voidTransactionId = $payfacReference . '-void';

            // Record void transaction
            $payment->setTransactionId($voidTransactionId);
            $payment->setParentTransactionId($payment->getParentTransactionId() ?: $payfacReference);
            $payment->setIsTransactionClosed(true);

            // Add void transaction
            $payment->addTransaction(
                \Magento\Sales\Model\Order\Payment\Transaction::TYPE_VOID,
                null,
                false,
                __('Voided authorization. Transaction ID: "%1"', $voidTransactionId)
            );

            // Store void details
            $payment->setAdditionalInformation('straumur_void_reference', $payfacReference);
            $payment->setAdditionalInformation('straumur_void_amount', $amount);
            $payment->setAdditionalInformation('straumur_void_reason', $reason);
            $payment->setAdditionalInformation('straumur_void_date', date('Y-m-d H:i:s'));

            // Cancel the order (void = cancel authorization)
            if ($order->canCancel()) {
                $order->cancel();
            } else {
                // Force cancel if canCancel() returns false
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
                $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            }

            $order->addCommentToStatusHistory(
                __('Payment voided successfully. Transaction ID: %1', $payfacReference),
                \Magento\Sales\Model\Order::STATE_CANCELED,
                true
            );

            $this->logger->info('Void webhook processed successfully', [
                'order_id' => $order->getIncrementId(),
                'payfac_reference' => $payfacReference,
                'amount' => $amount,
                'void_transaction_id' => $voidTransactionId
            ]);

        } else {
            // Void failed
            $order->addCommentToStatusHistory(
                __('Payment void failed via webhook. Transaction ID: %1, Reason: %2',
                   $payfacReference, $reason),
                false,
                true
            );

            $this->logger->warning('Void webhook reported failure', [
                'order_id' => $order->getIncrementId(),
                'payfac_reference' => $payfacReference,
                'reason' => $reason
            ]);
        }

        $this->orderRepository->save($order);

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'void',
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'transaction_id' => $payfacReference,
            'amount' => $amount,
            'reason' => $reason,
            'order_state' => $order->getState(),
            'order_status' => $order->getStatus()
        ];
    }

    /**
     * Check if order can be voided
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function canVoidOrder(OrderInterface $order): bool
    {
        // Allow void for orders in these states (authorized but not yet processed)
        return in_array($order->getState(), [
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'void';
    }
}
