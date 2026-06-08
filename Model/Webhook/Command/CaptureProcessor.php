<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\TransactionValidator;
use Straumur\Payment\Helper\Data as StraumurHelper;

class CaptureProcessor extends AbstractProcessor
{
    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

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
     * @param InvoiceSender $invoiceSender
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
        InvoiceSender $invoiceSender,
        TransactionCalculator $transactionCalculator,
        TransactionValidator $transactionValidator,
        StraumurHelper $straumurHelper
    ) {
        parent::__construct($validator, $gatewayConfig, $orderRepository, $searchCriteriaBuilder, $logger);
        $this->invoiceSender = $invoiceSender;
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
        
        if ($success) {
            $payment = $order->getPayment();

            // Check if this capture was already processed (idempotency)
            $captureHistory = $this->transactionCalculator->getTransactionHistory($payment, 'captures');
            foreach ($captureHistory as $capture) {
                if ($capture['reference'] === $payfacReference) {
                    // Log to debug only - no order comment needed for duplicates
                    $this->logger->info('Duplicate capture webhook ignored (idempotency check)', [
                        'order_id' => $order->getIncrementId(),
                        'payfac_reference' => $payfacReference,
                        'existing_capture_date' => $capture['date'],
                        'amount' => $amount
                    ]);

                    return [
                        'status' => 'success',
                        'event_type' => 'capture',
                        'order_id' => $order->getId(),
                        'order_increment_id' => $order->getIncrementId(),
                        'transaction_id' => $payfacReference,
                        'amount' => $amount,
                        'order_state' => $order->getState(),
                        'order_status' => $order->getStatus(),
                        'note' => 'Duplicate capture webhook ignored',
                        'invoice_created' => $order->hasInvoices()
                    ];
                }
            }

            // Validate capture operation using new validator
            try {
                $currency = $order->getOrderCurrencyCode();
                $amountInMajorUnits = $this->straumurHelper->convertFromMinorUnits($amount, $currency);
                $this->transactionValidator->validateCapture($order, $amountInMajorUnits);
            } catch (\Exception $e) {
                $this->logger->error('Capture validation failed', [
                    'order_id' => $order->getId(),
                    'amount' => $amount,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            $captureTransactionId = $payfacReference . '-capture';
            
            // Create capture transaction
            $payment->setTransactionId($captureTransactionId);
            $payment->setParentTransactionId($payment->getParentTransactionId() ?: $payfacReference);
            $payment->setIsTransactionClosed(true);
            
            // Add to transaction history (replaces old single-value storage)
            $this->transactionCalculator->addTransactionToHistory($payment, 'captures', [
                'amount' => $amount,
                'reference' => $payfacReference,
                'transaction_id' => $captureTransactionId,
                'date' => date('Y-m-d H:i:s'),
                'success' => true
            ]);
            
            // Keep backward compatibility for existing integrations
            $payment->setAdditionalInformation('straumur_capture_reference', $payfacReference);
            $payment->setAdditionalInformation('straumur_capture_amount', $amount);
            $payment->setAdditionalInformation('straumur_capture_date', date('Y-m-d H:i:s'));
            
            // Add capture transaction (transaction detail only, not order comment)
            $payment->addTransaction(
                \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE,
                null,
                false
            );

            // Create invoice if possible
            if ($order->canInvoice()) {
                try {
                    $invoice = $order->prepareInvoice();
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->setTransactionId($captureTransactionId);
                    $invoice->register();

                    $order->addRelatedObject($invoice);

                    // Magento already adds "Invoice #XXX created" comment automatically
                    // No need for additional comment

                    // Send invoice email if configured
                    if ($invoice->getCanSendNewEmailFlag()) {
                        try {
                            $this->invoiceSender->send($invoice);
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to send invoice email', [
                                'order_id' => $order->getId(),
                                'invoice_id' => $invoice->getId(),
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create invoice after capture', [
                        'order_id' => $order->getId(),
                        'error' => $e->getMessage()
                    ]);
                    
                    $order->addCommentToStatusHistory(
                        __('Payment captured but invoice creation failed: %1', $e->getMessage()),
                        false,
                        true
                    );
                }
            }
            
            // Update order state/status
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_PROCESSING));

            // No additional comment needed - transaction and invoice creation already logged
            
        } else {
            // Capture failed
            $order->addCommentToStatusHistory(
                __('Payment capture failed via webhook for amount: %1, Transaction ID: %2', $amount, $payfacReference),
                false,
                true
            );
            
            // If this was the only capture attempt, we might want to keep order in processing state
            // for manual retry, rather than cancelling
        }

        $this->orderRepository->save($order);

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'capture',
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'transaction_id' => $payfacReference,
            'amount' => $amount,
            'order_state' => $order->getState(),
            'order_status' => $order->getStatus(),
            'invoice_created' => $success && $order->hasInvoices()
        ];
    }

    /**
     * Check if order can be captured
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function canCaptureOrder(OrderInterface $order): bool
    {
        return in_array($order->getState(), [
            \Magento\Sales\Model\Order::STATE_PROCESSING,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
        ]) && $order->canInvoice();
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'capture';
    }
}