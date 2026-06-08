<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Helper\Data as StraumurHelper;

class CaptureHandler implements HandlerInterface
{
    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param SubjectReader $subjectReader
     * @param LoggerInterface $logger
     * @param TransactionCalculator $transactionCalculator
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        SubjectReader $subjectReader,
        LoggerInterface $logger,
        TransactionCalculator $transactionCalculator,
        StraumurHelper $straumurHelper
    ) {
        $this->subjectReader = $subjectReader;
        $this->logger = $logger;
        $this->transactionCalculator = $transactionCalculator;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $this->logger->info('Processing capture response', ['response' => $response]);

        if (empty($response)) {
            throw new LocalizedException(__('Empty response from Straumur API'));
        }

        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new LocalizedException(__('Invalid payment object'));
        }

        // Extract capture data from API response
        $status = $response['status'] ?? '';
        $payfacReference = $response['payfacReference'] ?? '';
        $responseDateTime = $response['responseDateTime'] ?? '';
        $responseIdentifier = $response['responseIdentifier'] ?? '';

        if (empty($payfacReference)) {
            throw new LocalizedException(__('No payfac reference received from Straumur'));
        }

        // Store capture data in payment additional information
        $payment->setAdditionalInformation('straumur_capture_status', $status);

        // Store capture reference separately - DO NOT overwrite straumur_payfac_reference
        // The authorization reference should remain unchanged
        $payment->setAdditionalInformation('straumur_capture_reference', $payfacReference);
        $payment->setAdditionalInformation('straumur_capture_datetime', $responseDateTime);
        $payment->setAdditionalInformation('straumur_capture_identifier', $responseIdentifier);

        // DO NOT SET straumur_payfac_reference here - it should remain the authorization reference

        // Add to transaction history for tracking (amount will come from webhook, use 0 as placeholder)
        // This prevents duplicate processing when webhook arrives
        $order = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        // Use helper to properly format amount for currency (handles ISK zero-decimal formatting)
        $captureAmount = $this->straumurHelper->formatAmount($order->getGrandTotal(), $currency);

        $this->transactionCalculator->addTransactionToHistory($payment, 'captures', [
            'amount' => $captureAmount,
            'reference' => $payfacReference,
            'transaction_id' => $payfacReference . '-capture',
            'date' => date('Y-m-d H:i:s'),
            'success' => true,
            'source' => 'admin_invoice' // Mark as admin-initiated
        ]);

        // Update transaction information
        $payment->setTransactionId($payfacReference);
        $payment->setIsTransactionClosed(true);
        $payment->setIsTransactionPending(false);

        // Update order status
        $order = $payment->getOrder();

        if (strtolower($status) === 'received') {
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus('processing');

            // Don't add comment here - Magento's invoice capture already adds
            // "Captured amount of X online. Transaction ID: Y" comment
        } else {
            // Only add comment if capture wasn't immediately received
            $this->logger->info('Capture request submitted with non-received status', [
                'status' => $status,
                'payfac_reference' => $payfacReference,
                'order_id' => $order->getId()
            ]);
        }

        $this->logger->info('Capture handler completed', [
            'payfac_reference' => $payfacReference,
            'status' => $status,
            'order_id' => $order->getId()
        ]);
    }
}