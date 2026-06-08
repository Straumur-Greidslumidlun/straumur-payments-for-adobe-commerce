<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Straumur\Payment\Model\Service\TransactionCalculator;

/**
 * Transaction Validator Service
 * 
 * Validates capture, refund, and adjustment operations against business rules.
 * Ensures compliance with Straumur API constraints and Magento order states.
 */
class TransactionValidator
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @param TransactionCalculator $transactionCalculator
     */
    public function __construct(
        TransactionCalculator $transactionCalculator
    ) {
        $this->transactionCalculator = $transactionCalculator;
    }

    /**
     * Validate if capture operation is allowed
     *
     * @param OrderInterface $order
     * @param float $amount Amount in base currency units (not minor units)
     * @throws LocalizedException
     * @return bool
     */
    public function validateCapture(OrderInterface $order, float $amount): bool
    {
        $payment = $order->getPayment();
        
        // Check order state allows capture
        if (!$this->canCaptureOrder($order)) {
            throw new LocalizedException(
                __('Order %1 cannot be captured in current state: %2', 
                    $order->getIncrementId(), 
                    $order->getState()
                )
            );
        }

        // Check remaining capturable amount
        $remainingCapturable = $this->transactionCalculator->getRemainingCapturableAmount($order);
        if ($amount > $remainingCapturable) {
            throw new LocalizedException(
                __('Cannot capture %1. Maximum capturable amount is %2', 
                    $amount, 
                    $remainingCapturable
                )
            );
        }

        // Validate minimum amount requirements
        $this->validateMinimumAmount($amount, $order->getOrderCurrencyCode());

        return true;
    }

    /**
     * Validate if refund operation is allowed
     *
     * @param OrderInterface $order
     * @param float $amount Amount in base currency units (not minor units)
     * @throws LocalizedException
     * @return bool
     */
    public function validateRefund(OrderInterface $order, float $amount): bool
    {
        $payment = $order->getPayment();
        
        // Check if manual capture mode requires prior capture
        if ($this->isManualCaptureMode($payment) && !$this->transactionCalculator->hasCapturedAmount($order)) {
            throw new LocalizedException(
                __('Cannot refund order %1: Manual capture transactions must be captured before refunding', 
                    $order->getIncrementId()
                )
            );
        }

        // Check remaining refundable amount
        $remainingRefundable = $this->transactionCalculator->getRemainingRefundableAmount($order);
        if ($amount > $remainingRefundable) {
            throw new LocalizedException(
                __('Cannot refund %1. Maximum refundable amount is %2', 
                    $amount, 
                    $remainingRefundable
                )
            );
        }

        // Check order can create credit memos
        if (!$order->canCreditmemo()) {
            throw new LocalizedException(
                __('Order %1 cannot create credit memos in current state', 
                    $order->getIncrementId()
                )
            );
        }

        // Validate minimum amount requirements
        $this->validateMinimumAmount($amount, $order->getOrderCurrencyCode());

        return true;
    }

    /**
     * Validate if reverse (full refund/cancel) operation is allowed
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return bool
     */
    public function validateReverse(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        
        // Check if there are any captured or refunded amounts
        $totalCaptured = $this->transactionCalculator->getTotalCapturedAmount($order);
        $totalRefunded = $this->transactionCalculator->getTotalRefundedAmount($order);
        
        if ($totalRefunded > 0) {
            throw new LocalizedException(
                __('Cannot reverse order %1: Order already has partial refunds. Use refund operation instead.', 
                    $order->getIncrementId()
                )
            );
        }

        // For captured transactions, reverse acts as full refund
        if ($totalCaptured > 0 && !$order->canCreditmemo()) {
            throw new LocalizedException(
                __('Order %1 cannot be reversed: Unable to create credit memo for captured amount', 
                    $order->getIncrementId()
                )
            );
        }

        return true;
    }

    /**
     * Validate if adjustment operation is allowed
     *
     * @param OrderInterface $order
     * @param float $amount Amount in base currency units (positive = charge, negative = credit)
     * @throws LocalizedException
     * @return bool
     */
    public function validateAdjustment(OrderInterface $order, float $amount): bool
    {
        $payment = $order->getPayment();
        
        // Critical: Adjustments only work on uncaptured authorizations
        if ($this->transactionCalculator->hasCapturedAmount($order)) {
            throw new LocalizedException(
                __('Cannot adjust order %1: Adjustment is only allowed for uncaptured authorizations', 
                    $order->getIncrementId()
                )
            );
        }

        // For downward adjustments, ensure we don't go negative
        if ($amount < 0) {
            $currentAuthorized = $this->transactionCalculator->getTotalAuthorizedAmount($order);
            $currentAdjustments = $this->transactionCalculator->getTotalAdjustmentAmount($order);
            $newTotal = $currentAuthorized + $currentAdjustments + $amount;
            
            if ($newTotal < 0) {
                throw new LocalizedException(
                    __('Cannot adjust order %1: Negative adjustment would result in negative authorization', 
                        $order->getIncrementId()
                    )
                );
            }
        }

        return true;
    }

    /**
     * Check if order can be captured
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function canCaptureOrder(OrderInterface $order): bool
    {
        // Allow capture for orders in processing or pending_payment states
        // Note: We don't check canInvoice() here because capture webhooks may arrive
        // after invoice was already created in auto-capture flow or manual admin action
        return in_array($order->getState(), [
            \Magento\Sales\Model\Order::STATE_PROCESSING,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            \Magento\Sales\Model\Order::STATE_NEW  // Allow for edge cases
        ]);
    }

    /**
     * Check if payment is in manual capture mode
     *
     * @param OrderPaymentInterface $payment
     * @return bool
     */
    private function isManualCaptureMode(OrderPaymentInterface $payment): bool
    {
        return (bool) $payment->getAdditionalInformation('straumur_is_manual_capture');
    }

    /**
     * Validate minimum amount requirements per currency
     *
     * @param float $amount
     * @param string $currencyCode
     * @throws LocalizedException
     * @return void
     */
    private function validateMinimumAmount(float $amount, string $currencyCode): void
    {
        $minimumAmounts = [
            'ISK' => 2.00,   // 200 minor units
            'EUR' => 0.01,   // 1 minor unit
            'USD' => 0.01,   // 1 minor unit
            'GBP' => 0.01,   // 1 minor unit
            'CAD' => 0.02,   // 2 minor units
            'DKK' => 0.07,   // 7 minor units
            'NOK' => 0.13,   // 13 minor units
            'SEK' => 0.13    // 13 minor units
        ];

        $minimumAmount = $minimumAmounts[$currencyCode] ?? 0.01;
        
        if ($amount < $minimumAmount) {
            throw new LocalizedException(
                __('Amount %1 is below minimum required for currency %2. Minimum: %3', 
                    $amount, 
                    $currencyCode, 
                    $minimumAmount
                )
            );
        }

        // Special validation for ISK - amounts must end in 00 (minor units)
        if ($currencyCode === 'ISK') {
            $minorUnits = (int) round($amount * 100);
            if ($minorUnits % 100 !== 0) {
                throw new LocalizedException(
                    __('ISK amounts must be whole numbers (no decimals). Amount %1 is invalid.', $amount)
                );
            }
        }
    }

    /**
     * Get validation summary for order operations
     *
     * @param OrderInterface $order
     * @return array
     */
    public function getOrderValidationStatus(OrderInterface $order): array
    {
        $summary = $this->transactionCalculator->getTransactionSummary($order);
        $payment = $order->getPayment();
        
        return [
            'can_capture' => $this->canCaptureOrder($order) && $summary['remaining_capturable'] > 0,
            'can_refund' => $order->canCreditmemo() && $summary['remaining_refundable'] > 0,
            'can_reverse' => $this->canPerformReverse($order),
            'can_adjust' => !$summary['has_captures'], // Only if no captures exist
            'is_manual_capture' => $this->isManualCaptureMode($payment),
            'requires_capture_for_refund' => $this->isManualCaptureMode($payment) && !$summary['has_captures'],
            'validation_errors' => $this->getValidationWarnings($order, $summary)
        ];
    }

    /**
     * Check if reverse operation can be performed
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function canPerformReverse(OrderInterface $order): bool
    {
        try {
            $this->validateReverse($order);
            return true;
        } catch (LocalizedException $e) {
            return false;
        }
    }

    /**
     * Get validation warnings for order
     *
     * @param OrderInterface $order
     * @param array $summary
     * @return array
     */
    private function getValidationWarnings(OrderInterface $order, array $summary): array
    {
        $warnings = [];
        
        if ($this->isManualCaptureMode($order->getPayment()) && !$summary['has_captures']) {
            $warnings[] = 'Manual capture mode: Order must be captured before refunding';
        }
        
        if ($summary['is_fully_captured']) {
            $warnings[] = 'Order is fully captured - no additional captures possible';
        }
        
        if ($summary['is_fully_refunded']) {
            $warnings[] = 'Order is fully refunded';
        }
        
        return $warnings;
    }
}