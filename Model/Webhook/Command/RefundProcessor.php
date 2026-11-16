<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\WebhookPayload;

class RefundProcessor extends AbstractProcessor
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param TransactionCalculator $transactionCalculator
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger,
        TransactionCalculator $transactionCalculator,
        StraumurHelper $straumurHelper
    ) {
        parent::__construct($gatewayConfig, $orderRepository, $logger);
        $this->transactionCalculator = $transactionCalculator;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * Check if this processor can handle the webhook
     *
     * Handles webhooks with eventType="Refund" when the order HAS captured amounts
     * (i.e., true refund of captured payment)
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

        return $this->transactionCalculator->hasCapturedAmount($order);
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
        $reason = WebhookPayload::string($payload, 'reason') ?: 'Customer request';

        // Convert amount to major units for display
        $currency = $order->getOrderCurrencyCode();
        $amountInMajorUnits = $this->straumurHelper->convertFromMinorUnits($amount, $currency);

        if ($success) {
            $order->addCommentToStatusHistory(
                __('Refund request confirmed by Straumur. Amount: %1, Transaction ID: %2',
                   $amountInMajorUnits, $payfacReference),
                false,
                true
            );
        } else {
            $order->addCommentToStatusHistory(
                __('Refund failed via webhook. Amount: %1, Reason: %2, Transaction ID: %3',
                   $amountInMajorUnits, $reason, $payfacReference),
                false,
                true
            );
        }

        $this->orderRepository->save($order);

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'refund',
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'transaction_id' => $payfacReference,
            'amount' => $amountInMajorUnits,
            'reason' => $reason,
            'order_state' => $order->getState(),
            'order_status' => $order->getStatus()
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'refund';
    }
}
