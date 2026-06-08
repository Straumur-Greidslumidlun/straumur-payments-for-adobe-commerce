<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Helper\Data as StraumurHelper;

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
     * @param \Straumur\Payment\Api\Webhook\ValidatorInterface $validator
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Psr\Log\LoggerInterface $logger
     * @param TransactionCalculator $transactionCalculator
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        \Straumur\Payment\Api\Webhook\ValidatorInterface $validator,
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Psr\Log\LoggerInterface $logger,
        TransactionCalculator $transactionCalculator,
        StraumurHelper $straumurHelper
    ) {
        parent::__construct($validator, $gatewayConfig, $orderRepository, $searchCriteriaBuilder, $logger);
        $this->transactionCalculator = $transactionCalculator;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * Check if this processor can handle the webhook
     *
     * Handles webhooks with eventType="Refund" when order HAS captured amounts
     * (i.e., true refund of captured payment)
     *
     * @param array $payload
     * @return bool
     */
    public function canProcess(array $payload): bool
    {
        // Check if this is a Refund event type
        $additionalData = $payload['additionalData'] ?? [];
        $eventType = $additionalData['eventType'] ?? $additionalData['EventType'] ?? '';

        if ($eventType !== 'Refund') {
            return false;
        }

        // Need to check if order has captures to distinguish refund from void
        $merchantReference = $payload['merchantReference'] ?? $payload['MerchantReference'] ?? '';
        if (empty($merchantReference)) {
            return false;
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $merchantReference)
                ->create();
            $orders = $this->orderRepository->getList($searchCriteria);

            if ($orders->getTotalCount() === 0) {
                return false;
            }

            $order = $orders->getItems()[array_key_first($orders->getItems())];

            // This processor handles refund: eventType=Refund + HAS captured amounts
            $hasCapturedAmounts = $this->transactionCalculator->hasCapturedAmount($order);

            $this->logger->info('RefundProcessor canProcess check', [
                'order_id' => $order->getIncrementId(),
                'has_captured_amounts' => $hasCapturedAmounts,
                'can_process' => $hasCapturedAmounts
            ]);

            return $hasCapturedAmounts; // Only process if has captures (true refund)

        } catch (\Exception $e) {
            $this->logger->error('Error checking if RefundProcessor can process webhook', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
        $reason = $payload['reason'] ?? $payload['Reason'] ?? 'Customer request';

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
