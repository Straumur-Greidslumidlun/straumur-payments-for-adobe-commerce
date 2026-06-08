<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\TransactionValidator;
use Straumur\Payment\Helper\Data as StraumurHelper;

class AdjustmentProcessor extends AbstractProcessor
{
    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var TransactionValidator
     */
    private $transactionValidator;

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
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param TransactionCalculator $transactionCalculator
     * @param TransactionValidator $transactionValidator
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        \Straumur\Payment\Api\Webhook\ValidatorInterface $validator,
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Psr\Log\LoggerInterface $logger,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoRepositoryInterface $creditmemoRepository,
        TransactionCalculator $transactionCalculator,
        TransactionValidator $transactionValidator,
        StraumurHelper $straumurHelper
    ) {
        parent::__construct($validator, $gatewayConfig, $orderRepository, $searchCriteriaBuilder, $logger);
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->transactionCalculator = $transactionCalculator;
        $this->transactionValidator = $transactionValidator;
        $this->straumurHelper = $straumurHelper;
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
        $reason = $payload['reason'] ?? $payload['Reason'] ?? 'Adjustment';
        
        if ($success) {
            $payment = $order->getPayment();
            
            // Validate adjustment operation using new validator
            try {
                $currency = $order->getOrderCurrencyCode();
                $amountInMajorUnits = $this->straumurHelper->convertFromMinorUnits($amount, $currency);
                $this->transactionValidator->validateAdjustment($order, $amountInMajorUnits);
            } catch (\Exception $e) {
                $this->logger->error('Adjustment validation failed', [
                    'order_id' => $order->getId(),
                    'amount' => $amount,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            // Add to transaction history
            $this->transactionCalculator->addTransactionToHistory($payment, 'adjustments', [
                'amount' => $amount,
                'reference' => $payfacReference,
                'reason' => $reason,
                'date' => date('Y-m-d H:i:s'),
                'success' => true
            ]);

            $currency = $order->getOrderCurrencyCode();
            $amountInMajorUnits = $this->straumurHelper->convertFromMinorUnits($amount, $currency);
            $order->addCommentToStatusHistory(
                __('Payment adjustment processed. Amount: %1, Reason: %2, Transaction ID: %3',
                    $amountInMajorUnits, $reason, $payfacReference)
            );
            
            // For positive adjustments (additional charges), create credit memo
            if ($order->canCreditmemo() && $amount > 0) {
                try {
                    $creditmemo = $this->creditmemoFactory->createByOrder($order, [
                        'adjustment_positive' => $amountInMajorUnits
                    ]);
                    $creditmemo->setInvoice($order->getInvoiceCollection()->getFirstItem());
                    $this->creditmemoRepository->save($creditmemo);
                    
                    $order->addCommentToStatusHistory(
                        __('Credit memo created for positive adjustment amount: %1', $amount)
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create credit memo for adjustment', [
                        'order_id' => $order->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } else {
            $order->addCommentToStatusHistory(
                __('Payment adjustment failed. Amount: %1, Reason: %2', $amount, $reason)
            );
        }

        $this->orderRepository->save($order);

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'adjustment',
            'order_id' => $order->getId(),
            'transaction_id' => $payfacReference,
            'amount' => $amount,
            'reason' => $reason
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'adjustment';
    }
}