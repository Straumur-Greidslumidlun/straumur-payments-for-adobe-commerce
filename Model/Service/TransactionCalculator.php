<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;

/**
 * Transaction Calculator Service
 *
 * Calculates totals and remaining amounts for Straumur payment transactions.
 * Handles multiple partial captures, refunds, and adjustments.
 */
class TransactionCalculator
{
    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        StraumurHelper $straumurHelper
    ) {
        $this->straumurHelper = $straumurHelper;
    }
    /**
     * Get total authorized amount from payment
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getTotalAuthorizedAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        $currency = $order->getOrderCurrencyCode();
        $authorizations = $this->getTransactionHistory($payment, 'authorizations');

        $totalMinorUnits = array_sum(array_column($authorizations, 'amount'));

        return $this->straumurHelper->convertFromMinorUnits($totalMinorUnits, $currency);
    }

    /**
     * Get total captured amount from all capture transactions
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getTotalCapturedAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        $currency = $order->getOrderCurrencyCode();
        $captures = $this->getTransactionHistory($payment, 'captures');

        $totalMinorUnits = array_sum(array_column($captures, 'amount'));

        return $this->straumurHelper->convertFromMinorUnits($totalMinorUnits, $currency);
    }

    /**
     * Get total refunded amount from all refund transactions
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getTotalRefundedAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        $currency = $order->getOrderCurrencyCode();
        $refunds = $this->getTransactionHistory($payment, 'refunds');

        $totalMinorUnits = array_sum(array_column($refunds, 'amount'));

        return $this->straumurHelper->convertFromMinorUnits($totalMinorUnits, $currency);
    }

    /**
     * Get total adjustment amount (positive = charges, negative = credits)
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getTotalAdjustmentAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        $currency = $order->getOrderCurrencyCode();
        $adjustments = $this->getTransactionHistory($payment, 'adjustments');

        $totalMinorUnits = array_sum(array_column($adjustments, 'amount'));

        return $this->straumurHelper->convertFromMinorUnits($totalMinorUnits, $currency);
    }

    /**
     * Calculate remaining capturable amount
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getRemainingCapturableAmount(OrderInterface $order): float
    {
        $totalAuthorized = $this->getTotalAuthorizedAmount($order);
        $totalCaptured = $this->getTotalCapturedAmount($order);
        $totalAdjustments = $this->getTotalAdjustmentAmount($order);

        // Adjustments can increase or decrease capturable amount
        $adjustedAuthorized = $totalAuthorized + $totalAdjustments;
        $remaining = max(0.0, $adjustedAuthorized - $totalCaptured);

        // Round to currency precision
        $currency = $order->getOrderCurrencyCode();
        $decimals = $this->straumurHelper->getCurrencyDecimals($currency);

        return round($remaining, $decimals);
    }

    /**
     * Calculate remaining refundable amount (based on captured amount)
     *
     * @param OrderInterface $order
     * @return float
     */
    public function getRemainingRefundableAmount(OrderInterface $order): float
    {
        $totalCaptured = $this->getTotalCapturedAmount($order);
        $totalRefunded = $this->getTotalRefundedAmount($order);
        $remaining = max(0.0, $totalCaptured - $totalRefunded);

        // Round to currency precision
        $currency = $order->getOrderCurrencyCode();
        $decimals = $this->straumurHelper->getCurrencyDecimals($currency);

        return round($remaining, $decimals);
    }

    /**
     * Check if order has any captures
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function hasCapturedAmount(OrderInterface $order): bool
    {
        return $this->getTotalCapturedAmount($order) > 0;
    }

    /**
     * Check if authorization is fully captured
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isFullyCaptured(OrderInterface $order): bool
    {
        $totalAuthorized = $this->getTotalAuthorizedAmount($order);
        $remainingCapturable = $this->getRemainingCapturableAmount($order);
        
        // Only show "Fully Captured" if there was something authorized AND nothing remaining to capture
        return $totalAuthorized > 0.01 && $remainingCapturable <= 0.01; // Account for minor unit precision
    }

    /**
     * Check if order is fully refunded
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isFullyRefunded(OrderInterface $order): bool
    {
        $totalCaptured = $this->getTotalCapturedAmount($order);
        $remainingRefundable = $this->getRemainingRefundableAmount($order);
        
        // Only show "Fully Refunded" if there was something captured AND nothing remaining to refund
        return $totalCaptured > 0.01 && $remainingRefundable <= 0.01; // Account for minor unit precision
    }

    /**
     * Get transaction history for a specific type
     *
     * @param OrderPaymentInterface $payment
     * @param string $type (authorizations|captures|refunds|adjustments)
     * @return array
     */
    public function getTransactionHistory(OrderPaymentInterface $payment, string $type): array
    {
        $history = $payment->getAdditionalInformation('straumur_transaction_history') ?? [];
        return $history[$type] ?? [];
    }

    /**
     * Get complete transaction history
     *
     * @param OrderPaymentInterface $payment
     * @return array
     */
    public function getCompleteTransactionHistory(OrderPaymentInterface $payment): array
    {
        return $payment->getAdditionalInformation('straumur_transaction_history') ?? [
            'authorizations' => [],
            'captures' => [],
            'refunds' => [],
            'adjustments' => []
        ];
    }

    /**
     * Add transaction to history
     *
     * @param OrderPaymentInterface $payment
     * @param string $type
     * @param array $transaction
     * @return void
     */
    public function addTransactionToHistory(OrderPaymentInterface $payment, string $type, array $transaction): void
    {
        $history = $this->getCompleteTransactionHistory($payment);
        
        // Ensure transaction has required fields
        $transaction = array_merge([
            'amount' => 0,
            'reference' => '',
            'date' => date('Y-m-d H:i:s'),
            'success' => true
        ], $transaction);
        
        $history[$type][] = $transaction;
        $payment->setAdditionalInformation('straumur_transaction_history', $history);
    }

    /**
     * Get transaction summary for display
     *
     * @param OrderInterface $order
     * @return array
     */
    public function getTransactionSummary(OrderInterface $order): array
    {
        return [
            'total_authorized' => $this->getTotalAuthorizedAmount($order),
            'total_captured' => $this->getTotalCapturedAmount($order),
            'total_refunded' => $this->getTotalRefundedAmount($order),
            'total_adjustments' => $this->getTotalAdjustmentAmount($order),
            'remaining_capturable' => $this->getRemainingCapturableAmount($order),
            'remaining_refundable' => $this->getRemainingRefundableAmount($order),
            'is_fully_captured' => $this->isFullyCaptured($order),
            'is_fully_refunded' => $this->isFullyRefunded($order),
            'has_captures' => $this->hasCapturedAmount($order)
        ];
    }
}