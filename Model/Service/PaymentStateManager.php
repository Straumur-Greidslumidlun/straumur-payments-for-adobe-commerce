<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Psr\Log\LoggerInterface;

class PaymentStateManager
{
    /**
     * Payment state flags
     */
    const FLAG_PAYMENT_AUTHORIZED = 'straumur_payment_authorized';
    const FLAG_CUSTOMER_RETURNED = 'straumur_customer_returned';
    const FLAG_READY_FOR_PROCESSING = 'straumur_ready_for_processing';
    
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var OrderSender
     */
    private $orderSender;
    
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param StraumurHelper $straumurHelper
     * @param InvoiceService $invoiceService
     * @param Transaction $transaction
     * @param InvoiceSender $invoiceSender
     * @param TransactionCalculator $transactionCalculator
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        StraumurHelper $straumurHelper,
        InvoiceService $invoiceService,
        Transaction $transaction,
        InvoiceSender $invoiceSender,
        TransactionCalculator $transactionCalculator,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->straumurHelper = $straumurHelper;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->transactionCalculator = $transactionCalculator;
        $this->logger = $logger;
    }
    
    /**
     * Mark payment as authorized and check if ready to process
     *
     * @param OrderInterface $order
     * @param string $payfacReference
     * @param float|null $amount
     * @param array $additionalData Additional webhook data to store
     * @return bool True if order was moved to processing
     */
    public function markPaymentAuthorized(OrderInterface $order, string $payfacReference, ?float $amount = null, array $additionalData = []): bool
    {
        $payment = $order->getPayment();
        
        // Set authorization flag and data
        $payment->setAdditionalInformation(self::FLAG_PAYMENT_AUTHORIZED, true);

        // Store authorization reference explicitly - this should NEVER be overwritten
        $payment->setAdditionalInformation('straumur_authorization_reference', $payfacReference);

        // Keep straumur_payfac_reference for backward compatibility
        // This will always point to the authorization reference
        $payment->setAdditionalInformation('straumur_payfac_reference', $payfacReference);

        $payment->setAdditionalInformation('straumur_authorization_amount', $amount);
        $payment->setAdditionalInformation('straumur_authorization_date', date('Y-m-d H:i:s'));
        
        // Store additional webhook data if provided
        if (!empty($additionalData['authCode'])) {
            $payment->setAdditionalInformation('straumur_auth_code', $additionalData['authCode']);
        }
        if (!empty($additionalData['cardNumber'])) {
            $payment->setAdditionalInformation('straumur_card_number', $additionalData['cardNumber']);
        }
        if (isset($additionalData['threeDAuthenticated'])) {
            $payment->setAdditionalInformation('straumur_3ds_authenticated', $additionalData['threeDAuthenticated']);
        }
        if (!empty($additionalData['cardUsage'])) {
            $payment->setAdditionalInformation('straumur_card_usage', $additionalData['cardUsage']);
        }
        
        // Store manual capture setting for this payment
        $storeId = $order->getStoreId() ? (int)$order->getStoreId() : null;
        $isManualCapture = $this->straumurHelper->isManualCapture($storeId);
        $payment->setAdditionalInformation('straumur_is_manual_capture', $isManualCapture);
        
        // Replace temporary session transaction ID with real payfac reference
        $tempTransactionId = $payment->getAdditionalInformation('straumur_temp_transaction_id');
        if ($tempTransactionId) {
            $payment->unsAdditionalInformation('straumur_temp_transaction_id');
            $this->logger->info('Replacing temporary transaction ID with real payfac reference', [
                'temp_id' => $tempTransactionId,
                'payfac_reference' => $payfacReference
            ]);
        }
        
        // Set real transaction details
        $payment->setTransactionId($payfacReference);
        $payment->setParentTransactionId($payfacReference);

        // Transaction closure depends on capture mode:
        // - Auto-capture: transaction is closed (payment captured immediately)
        // - Manual capture: transaction is open (can still be captured or voided)
        $payment->setIsTransactionClosed(!$isManualCapture);
        $payment->setIsTransactionPending(false);

        // Transaction type depends on capture mode:
        // - Auto-capture: TYPE_CAPTURE (authorization + capture in one step)
        // - Manual capture: TYPE_AUTH (authorization only, capture happens later)
        $transactionType = $isManualCapture
            ? \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH
            : \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;

        $transactionMessage = $isManualCapture
            ? __('Payment authorized via webhook (manual capture required). Amount: %1, Transaction ID: %2', $amount, $payfacReference)
            : __('Payment authorized and captured via webhook (auto-capture). Amount: %1, Transaction ID: %2', $amount, $payfacReference);

        $payment->addTransaction(
            $transactionType,
            null,
            false,
            $transactionMessage
        );

        // For auto-capture mode, add capture to transaction history
        // This allows refunds to work correctly by tracking captured amount
        // In auto-capture, the authorization reference IS the capture reference
        if (!$isManualCapture) {
            $this->transactionCalculator->addTransactionToHistory($payment, 'captures', [
                'amount' => $amount ?? $order->getGrandTotal() * 100,
                'reference' => $payfacReference,
                'date' => date('Y-m-d H:i:s'),
                'success' => true
            ]);

            // Store capture reference explicitly (same as authorization in auto-capture mode)
            $payment->setAdditionalInformation('straumur_capture_reference', $payfacReference);

            $this->logger->info('Added auto-capture to transaction history', [
                'order_id' => $order->getIncrementId(),
                'payfac_reference' => $payfacReference,
                'amount' => $amount
            ]);
        }

        $this->logger->info('Payment marked as authorized', [
            'order_id' => $order->getIncrementId(),
            'payfac_reference' => $payfacReference,
            'amount' => $amount,
            'is_manual_capture' => $isManualCapture,
            'transaction_type' => $isManualCapture ? 'authorization' : 'capture',
            'transaction_closed' => !$isManualCapture
        ]);
        
        // Check if customer has already returned
        $customerReturned = $payment->getAdditionalInformation(self::FLAG_CUSTOMER_RETURNED);
        
        if ($customerReturned) {
            // Customer already returned - check if we should move to processing
            if ($isManualCapture) {
                // Manual capture mode - stay in pending_payment until manual capture
                $order->addCommentToStatusHistory(
                    __('Payment authorized. Manual capture required before processing.'),
                    false,
                    true
                );

                $this->orderRepository->save($order);
                return false;
            } else {
                // Auto-capture mode - move to processing now
                return $this->moveOrderToProcessing($order, 'Payment authorized and captured');
            }
        } else {
            // Customer hasn't returned yet - stay pending with appropriate message
            $message = $isManualCapture
                ? __('Payment authorized. Manual capture required.')
                : __('Payment authorized.');

            $order->addCommentToStatusHistory($message, false, true);
            $this->orderRepository->save($order);
            return false;
        }
    }
    
    /**
     * Mark customer as returned and check if ready to process
     *
     * @param OrderInterface $order
     * @return bool True if order was moved to processing
     */
    public function markCustomerReturned(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        
        // Set customer returned flag
        $payment->setAdditionalInformation(self::FLAG_CUSTOMER_RETURNED, true);
        $payment->setAdditionalInformation('straumur_customer_return_date', date('Y-m-d H:i:s'));
        
        $this->logger->info('Customer marked as returned', [
            'order_id' => $order->getIncrementId()
        ]);
        
        // Check if payment has already been authorized
        $paymentAuthorized = $payment->getAdditionalInformation(self::FLAG_PAYMENT_AUTHORIZED);
        
        if ($paymentAuthorized) {
            // Payment already authorized - check if manual capture is enabled
            $isManualCapture = (bool)$payment->getAdditionalInformation('straumur_is_manual_capture');
            
            if ($isManualCapture) {
                // Manual capture mode - stay in pending_payment until manual capture
                // Don't add duplicate comment - authorization webhook already noted this
                $this->logger->info('Customer returned - manual capture required', [
                    'order_id' => $order->getIncrementId()
                ]);

                $this->orderRepository->save($order);
                return false;
            } else {
                // Auto-capture mode - move to processing now
                return $this->moveOrderToProcessing($order, 'Payment complete');
            }
        } else {
            // Payment not authorized yet - stay pending with note
            $order->addCommentToStatusHistory(
                __('Customer returned from payment page. Awaiting payment confirmation.'),
                false,
                false
            );

            $this->orderRepository->save($order);
            return false;
        }
    }
    
    /**
     * Check if both conditions are met for processing
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isReadyForProcessing(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        
        $paymentAuthorized = $payment->getAdditionalInformation(self::FLAG_PAYMENT_AUTHORIZED);
        $customerReturned = $payment->getAdditionalInformation(self::FLAG_CUSTOMER_RETURNED);
        
        return $paymentAuthorized && $customerReturned;
    }
    
    /**
     * Get current payment state summary
     *
     * @param OrderInterface $order
     * @return array
     */
    public function getPaymentState(OrderInterface $order): array
    {
        $payment = $order->getPayment();
        
        return [
            'payment_authorized' => (bool)$payment->getAdditionalInformation(self::FLAG_PAYMENT_AUTHORIZED),
            'customer_returned' => (bool)$payment->getAdditionalInformation(self::FLAG_CUSTOMER_RETURNED),
            'ready_for_processing' => (bool)$payment->getAdditionalInformation(self::FLAG_READY_FOR_PROCESSING),
            'authorization_date' => $payment->getAdditionalInformation('straumur_authorization_date'),
            'customer_return_date' => $payment->getAdditionalInformation('straumur_customer_return_date'),
            'payfac_reference' => $payment->getAdditionalInformation('straumur_payfac_reference')
        ];
    }
    
    /**
     * Move order to processing state
     *
     * @param OrderInterface $order
     * @param string $comment
     * @return bool
     */
    private function moveOrderToProcessing(OrderInterface $order, string $comment): bool
    {
        $payment = $order->getPayment();

        // Mark as ready for processing
        $payment->setAdditionalInformation(self::FLAG_READY_FOR_PROCESSING, true);
        $payment->setAdditionalInformation('straumur_processing_date', date('Y-m-d H:i:s'));

        // Check if this is auto-capture mode
        $isManualCapture = (bool)$payment->getAdditionalInformation('straumur_is_manual_capture');

        // In auto-capture mode, create invoice automatically
        if (!$isManualCapture && $order->canInvoice()) {
            try {
                $this->createInvoiceForOrder($order, $payment);
            } catch (\Exception $e) {
                $this->logger->error('Failed to create invoice in auto-capture mode', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage()
                ]);

                $order->addCommentToStatusHistory(
                    __('Auto-capture: Invoice creation failed - %1', $e->getMessage()),
                    false,
                    true
                );
            }
        }

        // Update order state to processing
        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_PROCESSING));

        // Add comment about the state change
        $order->addCommentToStatusHistory(
            __('%1. Order moved to processing - ready for fulfillment.', $comment),
            false,
            true
        );

        // Send order confirmation email if not sent yet
        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
                $order->addCommentToStatusHistory(__('Order confirmation email sent'));
            } catch (\Exception $e) {
                $this->logger->error('Failed to send order confirmation email', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Order moved to processing', [
            'order_id' => $order->getIncrementId(),
            'comment' => $comment,
            'auto_capture' => !$isManualCapture
        ]);

        $this->orderRepository->save($order);
        return true;
    }

    /**
     * Create invoice for order in auto-capture mode
     *
     * @param OrderInterface $order
     * @param OrderPaymentInterface $payment
     * @return void
     * @throws \Exception
     */
    private function createInvoiceForOrder(OrderInterface $order, OrderPaymentInterface $payment): void
    {
        // Use authorization reference for invoice (this is what was actually authorized)
        $payfacReference = $payment->getAdditionalInformation('straumur_authorization_reference')
            ?? $payment->getAdditionalInformation('straumur_payfac_reference'); // Backward compatibility
        $authorizationAmount = $payment->getAdditionalInformation('straumur_authorization_amount');

        $this->logger->info('Creating invoice for auto-capture order', [
            'order_id' => $order->getIncrementId(),
            'payfac_reference' => $payfacReference,
            'amount' => $authorizationAmount
        ]);

        // Prepare invoice
        $invoice = $this->invoiceService->prepareInvoice($order);

        if (!$invoice || !$invoice->getTotalQty()) {
            throw new \Exception('Cannot create an invoice without products');
        }

        // Set invoice details
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($payfacReference);
        $invoice->register();

        // Save invoice and order in transaction
        $this->transaction->addObject($invoice)
            ->addObject($order)
            ->save();

        $order->addCommentToStatusHistory(
            __('Invoice #%1 created automatically (auto-capture mode). Transaction ID: %2',
                $invoice->getIncrementId(),
                $payfacReference
            ),
            false,
            true
        );

        // Send invoice email if configured
        if ($invoice->getEmailSent() !== true) {
            try {
                $this->invoiceSender->send($invoice);
                $order->addCommentToStatusHistory(__('Invoice email sent to customer'));
            } catch (\Exception $e) {
                $this->logger->error('Failed to send invoice email', [
                    'order_id' => $order->getIncrementId(),
                    'invoice_id' => $invoice->getIncrementId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Invoice created successfully for auto-capture order', [
            'order_id' => $order->getIncrementId(),
            'invoice_id' => $invoice->getIncrementId(),
            'payfac_reference' => $payfacReference
        ]);
    }

    /**
     * Check if payment has been authorized
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isPaymentAuthorized(OrderInterface $order): bool
    {
        return (bool)$order->getPayment()->getAdditionalInformation(self::FLAG_PAYMENT_AUTHORIZED);
    }
    
    /**
     * Check if customer has returned
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function hasCustomerReturned(OrderInterface $order): bool
    {
        return (bool)$order->getPayment()->getAdditionalInformation(self::FLAG_CUSTOMER_RETURNED);
    }
}